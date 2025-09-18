<?php
// Sécurité : script obligatoirement inclus par ajax.php
if ( !defined('OK') )  exit();

// Script d'exécution des commandes ajax pour l'administration
// Nécessite d'être administrateur ou professeur de la matière concernée en connexion normale
$matiereassociee = ( $autorisation == 5 ) && in_array($mid = intval($_REQUEST['matiere'] ?? $_REQUEST['id'] ?? -1), explode(',',$_SESSION['matieres']));
if ( !$matiereassociee && !$_SESSION['admin'] || !connexionlight() )
  exit( '{"etat":"nok","message":"Aucune action effectuée"}' );
$mysqli = connectsql(true);
// Spécifications pour les manipulations de caractères sur 2 octets (accents)
mb_internal_encoding('UTF-8');

  
////////////////////////////
// Modification des pages //
////////////////////////////
if ( ( $action == 'pages' ) && ctype_digit($id = $_REQUEST['id'] ?? '') )  {

  // Vérification que l'identifiant est valide
  $resultat = $mysqli->query("SELECT ordre, matiere, nom, cle, protection,
                              FIND_IN_SET(matiere,'${_SESSION['matieres']}') AS matiereassociee, (SELECT COUNT(*) FROM pages WHERE matiere = p.matiere) AS max 
                              FROM pages AS p WHERE id = $id");
  if ( !$resultat->num_rows )
    exit('{"etat":"nok","message":"Identifiant de page non valide"}');
  $r = $resultat->fetch_assoc();
  $resultat->free();

  // Traitement d'une modification globale (depuis index.php ou pages.php)
  if ( isset($_REQUEST['bandeau']) && ctype_digit($protection = $_REQUEST['protection'] ?? '') && ctype_digit($edition = $_REQUEST['edition'] ?? '') )  {
    $titre = mb_strtoupper(mb_substr($titre = trim(strip_tags($mysqli->real_escape_string($_REQUEST['titre'] ?? ''))) ,0,1)).mb_substr($titre,1);
    $nom = mb_strtoupper(mb_substr($nom = trim(strip_tags($mysqli->real_escape_string($_REQUEST['nom'] ?? ''))),0,1)).mb_substr($nom,1);
    $cle = str_replace(' ','_',strip_tags(trim($mysqli->real_escape_string($_REQUEST['cle'] ?? ''))));
    $bandeau = trim($mysqli->real_escape_string($_REQUEST['bandeau']));
    if ( !$titre || !$nom || !$cle )
      exit("{\"etat\":\"nok\",\"message\":\"La page <em>${r['nom']}</em> n'a pas été modifiée. Le titre, le nom et la clé doivent être non vides.\"}");
    // Partie de requête si modification de matière (impossible si première page)
    // N'existe qu'en provenance de pages.php (et non d'index.php)
    $requete_matiere = '';
    if ( ctype_digit($matiere = $_REQUEST['matiere'] ?? '') && ( $matiere != $r['matiere'] ) && ( $id > 1 ) )  {
      // Pas de problème si on reste dans les matières d'un compte prof
      if ( ( $autorisation == 5 ) && in_array($matiere,explode(',',$_SESSION['matieres'])) )
         $requete_matiere = ", matiere = $matiere";
      // Possible aussi pour un administrateur, vers toutes les matières
      elseif ( $_SESSION['admin'] )  {
        $resultat->query('SELECT GROUP_CONCAT(id) FROM matieres');
        $requete_matiere = ( in_array($matiere,explode(',',$resultat->fetch_row()[0])) ) ? ", matiere = $matiere" : '';
      }
      else
        $matiere = $r['matiere'];
    }
    else 
      $matiere = $r['matiere'];
    // Vérification que la clé n'existe pas déjà
    if ( $cle != $r['cle'] )  {
      $resultat = $mysqli->query("SELECT GROUP_CONCAT(cle SEPARATOR \" \") FROM pages WHERE matiere = $matiere");
      if ( $resultat->num_rows )  {
        if ( in_array($cle,explode(' ',$resultat->fetch_row()[0])) )
          exit("{\"etat\":\"nok\",\"message\":\"La page <em>${r['nom']}</em> n'a pas été modifiée. La clé donnée existe déjà. Elle doit être différente de celles des autres pages.\"}"); 
        $resultat->free();
      }
    }
    // Sans matière, protection non nulle entre 1 et 15, ou 32 
    if ( !$matiere && $protection ) 
      $protection = ( $protection & 15 ) ?: 32;
    // Validation de l'édition. La protection doit obligatoirement inclure l'édition.
    $edition = ( $edition ) ? ($edition = ($edition-1) & (32-($protection?:1)) & 30) + ($edition>0) : 0;
    // Écriture
    if ( !requete('pages',"UPDATE pages SET titre = '$titre', nom = '$nom', cle = '$cle', bandeau = '$bandeau', protection = $protection, edition = $edition$requete_matiere WHERE id = $id",$mysqli) )
      exit("{\"etat\":\"nok\",\"message\":\"La page <em>${r['nom']}</em> n'a pas été modifiée. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');
    // Mise à jour du menu si changement de protection
    if ( ( $protection != $r['protection'] ) && !$requete_matiere )
      requete('utilisateurs',"UPDATE utilisateurs SET menuelements='' WHERE FIND_IN_SET($matiere,menumatieres)",$mysqli);
    // Changement de matière : déplacement des pages sur les matières concernées
    if ( $requete_matiere )  {
      requete('pages',"UPDATE pages SET ordre = (SELECT COUNT(*) FROM (SELECT id FROM pages AS p WHERE p.matiere = $matiere) AS p1) WHERE id = $id",$mysqli);
      requete('pages',"UPDATE pages SET ordre = (ordre-1) WHERE matiere = ${r['matiere']} AND ordre > ${r['ordre']}",$mysqli);
      // Mise à jour de la table recents, des flux RSS
      requete('recents',"UPDATE recents SET matiere = $matiere, 
                                            titre = CONCAT(SUBSTRING_INDEX(titre,'[',1), ( SELECT CONCAT('[',IF(matiere=0,'',CONCAT(m.nom,'/')),p.nom,']')
                                                                                           FROM pages AS p LEFT JOIN matieres AS m ON matiere=m.id WHERE p.id = $id ) )
                         WHERE type = 1 AND id IN (SELECT id FROM infos WHERE page = $id)",$mysqli);
      rss($mysqli,array($r['matiere'],$matiere),0);
      // Mise à jour du menu
      requete('utilisateurs',"UPDATE utilisateurs SET menuelements='' WHERE FIND_IN_SET($matiere,menumatieres) OR FIND_IN_SET(${r['matiere']},menumatieres)",$mysqli);
    }
    // Changement de nom : modification de la table recents et des flux RSS (inutile si matière modifiée, impossible si première page)
    elseif ( ( $nom != $r['nom'] ) && ( $id > 1 ) )  {
      requete('recents',"UPDATE recents SET titre = CONCAT(SUBSTRING_INDEX(titre,'[',1), ( SELECT CONCAT('[',IF(matiere=0,'',CONCAT(m.nom,'/')),p.nom,']')
                                                                                           FROM pages AS p LEFT JOIN matieres AS m ON matiere=m.id WHERE p.id = $id ) )
                         WHERE type = 1 AND id IN (SELECT id FROM infos WHERE page = $id)",$mysqli);
      rss($mysqli,$matiere,0);
    }
    // Changement de clé donc de lien dans la table recents
    if ( $cle != $r['cle'] )  {
      requete('recents',"UPDATE recents SET lien = '?$cle' WHERE type = 1 AND id IN (SELECT id FROM infos WHERE page = $id)",$mysqli);
      rss($mysqli,$matiere,0);
    }
    // Propagation de la protection : modification des infos
    if ( isset($_REQUEST['propagation']) )  {
      // La protection de la page doit obligatoirement inclure l'édition de chaque info.
      $edition = ( $edition ) ? ($edition = ($edition-1) & (32-($protection?:1)) & 30) + ($edition>0) : 0;
      requete('infos',"UPDATE infos SET protection = $protection, edition = $edition WHERE page = $id",$mysqli);
      // Mise à jour de la table recents et des flux RSS
      requete('recents',"UPDATE recents SET protection = $protection WHERE type = 1 AND id IN (SELECT id FROM infos WHERE page = $id)",$mysqli);
      rss($mysqli,$matiere,0);
    }
    // Mise à jour des éditions des informations si changement de protection sans
    // propagation : la protection de la page doit inclure l'édition de chaque info
    elseif ( $protection != $r['protection'] )  {
      $masque = ( 32 - ($protection?:1) ) & 30;
      requete('infos',"UPDATE infos SET edition = IF(edition, (edition-1) & $masque, 0) WHERE page = $id",$mysqli);
      requete('infos',"UPDATE infos SET edition = IF(edition, edition+1, 0) WHERE page = $id",$mysqli);
    }
    exit($_SESSION['message'] = "{\"etat\":\"ok\",\"message\":\"La page <em>${r['nom']}</em> a été modifiée.\",\"reload\":\"1\"}");
  }

  // Déplacement vers le haut
  $matiere = $r['matiere'];
  if ( isset($_REQUEST['monte']) && ( $r['ordre'] > 1+!$matiere ) )
    exit( requete('pages',"UPDATE pages SET ordre = (2*${r['ordre']}-1-ordre) WHERE matiere = $matiere AND ( ordre = ${r['ordre']} OR ordre = (${r['ordre']}-1) )",$mysqli) 
       && requete('utilisateurs',"UPDATE utilisateurs SET menuelements='' WHERE FIND_IN_SET($matiere,menumatieres)",$mysqli)
        ? "{\"etat\":\"ok\",\"message\":\"Le déplacement de la page <em>${r['nom']}</em> a été réalisé.\"}"
        : "{\"etat\":\"nok\",\"message\":\"La page <em>${r['nom']}</em> n'a pas été déplacée. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');

  // Déplacement vers le bas
  if ( isset($_REQUEST['descend']) && ( $r['ordre'] < $r['max'] ) && ( $id > 1 ) )
    exit( requete('pages',"UPDATE pages SET ordre = (2*${r['ordre']}+1-ordre) WHERE matiere = $matiere AND ( ordre = ${r['ordre']} OR ordre = (${r['ordre']}+1) )",$mysqli) 
       && requete('utilisateurs',"UPDATE utilisateurs SET menuelements='' WHERE FIND_IN_SET($matiere,menumatieres)",$mysqli)
        ? "{\"etat\":\"ok\",\"message\":\"Le déplacement de la page <em>${r['nom']}</em> a été réalisé.\"}"
        : "{\"etat\":\"nok\",\"message\":\"La page <em>${r['nom']}</em> n'a pas été déplacée. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');

  // Suppression
  if ( isset($_REQUEST['supprime']) )  {
    if ( $id == 1 )
      exit("{\"etat\":\"nok\",\"message\":\"La page <em>${r['nom']}</em> ne peut pas être supprimée.\"}");
    if ( requete('pages',"DELETE FROM pages WHERE id = $id",$mysqli)
      && requete('recents',"DELETE FROM recents WHERE type = 1 AND id IN (SELECT id FROM infos WHERE page = $id)",$mysqli)
      && requete('infos',"DELETE FROM infos WHERE page = $id",$mysqli)
      && requete('pages',"UPDATE pages SET ordre = (ordre-1) WHERE matiere = $matiere AND ordre > ${r['ordre']}",$mysqli)
      && requete('utilisateurs',"UPDATE utilisateurs SET menuelements='' WHERE FIND_IN_SET($matiere,menumatieres)",$mysqli)
      && rss($mysqli,$matiere,0) )
      exit("{\"etat\":\"ok\",\"message\":\"La page <em>${r['nom']}</em> a été supprimée. Les informations contenues ont été supprimées.'\"}");
    exit("{\"etat\":\"nok\",\"message\":\"La page <em>${r['nom']}</em> n'a pas été supprimée. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');
  }

  // Suppression des informations
  if ( isset($_REQUEST['supprime_infos']) )
    exit( requete('recents',"DELETE FROM recents WHERE type = 1 AND id IN (SELECT id FROM infos WHERE page = $id)",$mysqli)
       && requete('infos',"DELETE FROM infos WHERE page = $id",$mysqli)
       && rss($mysqli,$matiere,0)
      ? "{\"etat\":\"ok\",\"message\":\"Les informations de la page <em>${r['nom']}</em> ont été supprimées.\"}"
      : "{\"etat\":\"nok\",\"message\":\"Les informations de la page <em>${r['nom']}</em> n'ont pas été supprimées. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');
}

//////////////////////
// Ajout d'une page //
//////////////////////
elseif ( ( $action == 'ajout-page' ) && isset($_REQUEST['bandeau']) && in_array($matiere = intval($_REQUEST['matiere'] ?? -1),explode(',',$_SESSION['matieres'])) && ctype_digit($protection = $_REQUEST['protection'] ?? '') && ctype_digit($edition = $_REQUEST['edition'] ?? '') )  {
  $titre = mb_strtoupper(mb_substr($titre = trim(strip_tags($mysqli->real_escape_string($_REQUEST['titre'] ?? ''))) ,0,1)).mb_substr($titre,1);
  $nom = mb_strtoupper(mb_substr($nom = trim(strip_tags($mysqli->real_escape_string($_REQUEST['nom'] ?? ''))),0,1)).mb_substr($nom,1);
  $cle = str_replace(' ','_',strip_tags(trim($mysqli->real_escape_string($_REQUEST['cle'] ?? ''))));
  $bandeau = trim($mysqli->real_escape_string($_REQUEST['bandeau']));
  if ( !$titre || !$nom || !$cle )
    exit('{"etat":"nok","message":"La page n\'a pas été ajoutée. Le titre, le nom et la clé doivent être non vides."}');
  // Vérification que la clé n'existe pas déjà
  $resultat = $mysqli->query("SELECT cle FROM pages WHERE matiere = $matiere");
  if ( $resultat->num_rows )  {
    while ( $r = $resultat->fetch_row() )
      if ( $r[0] == $cle )
        exit("{\"etat\":\"nok\",\"message\":\"La page <em>$nom</em> n'a pas été ajoutée. La clé donnée existe déjà. Elle doit être différente de celles des autres pages.\"}");
    $resultat->free();
  }
  // Sans matière, protection non nulle entre 1 et 15, ou 32 
  if ( !$matiere && $protection ) 
    $protection = ( $protection & 15 ) ?: 32;
  // La protection doit obligatoirement inclure l'édition
  $edition = ( $edition ) ? ($edition = ($edition-1) & (32-($protection?:1)) & 30) + ($edition>0) : 0;
  // Écriture
  if ( requete('pages',"INSERT INTO pages SET titre = '$titre', nom = '$nom', cle = '$cle', matiere = $matiere, bandeau = '$bandeau', protection = $protection, edition = $edition, ordre = (SELECT IFNULL(MAX(ordre)+1,1) FROM pages AS p WHERE p.matiere = $matiere)",$mysqli) )  {
    requete('utilisateurs',"UPDATE utilisateurs SET menuelements='' WHERE FIND_IN_SET($matiere,menumatieres)",$mysqli);
    exit($_SESSION['message'] = "{\"etat\":\"ok\",\"message\":\"La page <em>$nom</em> a été ajoutée.\",\"reload\":\"1\"}");
  }
  exit("{\"etat\":\"nok\",\"message\":\"La page <em>$nom</em> n'a pas été ajoutée. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');
}

///////////////////////////////
// Modification des matières //
///////////////////////////////
elseif ( ( $action == 'matieres' ) && ( ( $mid = intval($_REQUEST['id'] ?? '') ) > 0 ) )  {
  
  // Déplacements (possibles pour toute matière, contrairement au reste)
  if ( isset($_REQUEST['monte']) || isset($_REQUEST['descend']) )  {
    $resultat = $mysqli->query("SELECT ordre, nom, (SELECT COUNT(*) FROM matieres) AS max FROM matieres WHERE id = $mid");
    if ( !$resultat->num_rows )
      exit('{"etat":"nok","message":"Identifiant de matière non valide"}');
    $r = $resultat->fetch_assoc();
    $resultat->free();
    
    // Déplacement vers le haut
    if ( isset($_REQUEST['monte']) && ( $r['ordre'] > 1 ) )
      exit( requete('matieres',"UPDATE matieres SET ordre = (2*${r['ordre']}-1-ordre) WHERE ( ordre = ${r['ordre']} OR ordre = (${r['ordre']}-1) )",$mysqli)
         && requete('utilisateurs',"UPDATE utilisateurs SET menuelements='' WHERE FIND_IN_SET($mid,menumatieres)",$mysqli)
        ? "{\"etat\":\"ok\",\"message\":\"Le déplacement de la matière <em>${r['nom']}</em> a été réalisé.\"}"
        : "{\"etat\":\"nok\",\"message\":\"La matière <em>${r['nom']}</em> n'a pas été déplacée. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');

    // Déplacement vers le bas
    if ( isset($_REQUEST['descend']) && ( $r['ordre'] < $r['max'] ) )
      exit( requete('matieres',"UPDATE matieres SET ordre = (2*${r['ordre']}+1-ordre) WHERE ( ordre = ${r['ordre']} OR ordre = (${r['ordre']}+1) )",$mysqli)
         && requete('utilisateurs',"UPDATE utilisateurs SET menuelements='' WHERE FIND_IN_SET($mid,menumatieres)",$mysqli)
        ? "{\"etat\":\"ok\",\"message\":\"Le déplacement de la matière <em>${r['nom']}</em> a été réalisé.\"}"
        : "{\"etat\":\"nok\",\"message\":\"La matière <em>${r['nom']}</em> n'a pas été déplacée. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');
  }
  
  // Récupération des propriétés actuelles de la matières
  $resultat = $mysqli->query("SELECT ordre, cle, nom, progcolles, cdt, docs, progcolles_protection, cdt_protection, docs_protection, transferts, transferts_protection, notescolles, dureecolles, heurescolles, (SELECT COUNT(*) FROM matieres) AS max FROM matieres WHERE id = $mid");
  $r = $resultat->fetch_assoc();
  $resultat->free();

  // Traitement d'une modification
  if ( isset($_REQUEST['transferts']) && isset($_REQUEST['notescolles']) && isset($_REQUEST['dureecolles']) && isset($_REQUEST['heurescolles']) )  {
    $requete = [];
    // Nom et clé : récupération et vérification
    $nom = mb_strtoupper(mb_substr($nom = trim(strip_tags($_REQUEST['nom'] ?? '')),0,1)).mb_substr($nom,1);
    $cle = str_replace(' ','_',strip_tags(trim($_REQUEST['cle'] ?? '')));
    if ( !$nom || !$cle )
      exit("{\"etat\":\"nok\",\"message\":\"La matière <em>${r['nom']}</em> n'a pas été modifiée. Le nom et la clé sont obligatoires.\"}");
    if ( $nom != $r['nom'] )
      $requete['nom'] = 'nom = \''.$mysqli->real_escape_string($nom).'\'';
    if ( $cle != $r['cle'] )  {
      // Vérification que la clé n'existe pas déjà
      $resultat = $mysqli->query('SELECT cle FROM matieres');
      if ( $resultat->num_rows )  {
        while ( $s = $resultat->fetch_row() )
          if ( $s[0] == $cle )
            exit("{\"etat\":\"nok\",\"message\":\"La matière <em>${r['nom']}</em> n'a pas été modifiée. La clé donnée existe déjà. Elle doit être différente de celles des autres matières.\"}");
        $resultat->free();
      }
      $requete['cle'] = 'cle = \''.$mysqli->real_escape_string($cle).'\'';
    }
    
    // Récupération et vérification des valeurs de protection
    foreach ( array('progcolles','cdt','docs') as $fonction )  {
      if ( !ctype_digit($val = $_REQUEST["{$fonction}_protection"] ?? '') || ( $val < 0 ) || ( $val > 33 ) )
        exit("{\"etat\":\"nok\",\"message\":\"La matière <em>$nom</em> n'a pas été modifiée. Une des protections d'accès est incorrecte.\"}");
      // Désactivation
      if ( ( $val == 33 ) && ( $r[$fonction] < 2 ) )
        $requete[$fonction] = "$fonction = 2, {$fonction}_protection = 32";
      // Modification si $r[$fonction] < 2, activation si $r[$fonction] = 2
      elseif ( $val < 33 )
        $requete[$fonction] = ( $r[$fonction] < 2 ) ? "{$fonction}_protection = $val" : "$fonction = IF( (SELECT id FROM $fonction WHERE matiere = $mid LIMIT 1),1,0 ), {$fonction}_protection = $val";
    }
    // Transferts : 2-> désactivée ; 1-> possible (0 ou 1 dans la base)
    // Protection à ajuster en fonction des transferts existants
    if ( in_array($transferts = $_REQUEST['transferts'], array(1,2)) && ( $transferts != max(1,$r['transferts']) ) )
      $requete['transferts'] = ( $transferts == 2 ) ? 'transferts = 2, transferts_protection = 32' : "transferts = IF( (SELECT id FROM transferts WHERE matiere = $mid LIMIT 1) ,1,0 ), transferts_protection = IFNULL( (SELECT 32-(BIT_OR(type)<<1|2) FROM transferts WHERE matiere = $mid), 32)";
    // Notes de colles : 2-> désactivée ; 1-> possible (0 ou 1 dans la base)
    if ( in_array($notescolles = $_REQUEST['notescolles'], array(1,2)) && ( $notescolles != max(1,$r['notescolles']) ) )
      $requete['notes'] = ( $notescolles == 2 ) ? 'notescolles = 2' : "notescolles = IF( (SELECT id FROM notescolles WHERE matiere = $mid LIMIT 1),1,0 )";
    if ( ( $dureecolles = intval($_REQUEST['dureecolles']) ?: 20 ) != $r['dureecolles'] )
      $requete['dureecolles'] = "dureecolles = $dureecolles";
    if ( ( $heurescolles = intval( $_REQUEST['heurescolles'] == 1 ) ) != $r['heurescolles'] )
      $requete['heurescolles'] = "heurescolles = $heurescolles";

    // Écriture
    if ( !count($requete) )
      exit("{\"etat\":\"nok\",\"message\":\"La matière <em>${r['nom']}</em> n'a pas été modifiée. Aucune modification demandée.\"}");
    if ( !requete('matieres','UPDATE matieres SET '.implode(', ',$requete)." WHERE id = $mid",$mysqli) )
      exit("{\"etat\":\"nok\",\"message\":\"La matière <em>${r['nom']}</em> n'a pas été modifiée. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');
    requete('utilisateurs',"UPDATE utilisateurs SET menuelements='' WHERE FIND_IN_SET($mid,menumatieres)",$mysqli);
      
    // Autres modications : recents et reps si renommage (nom et clé)
    if ( isset($requete['nom']) )  {
      $nom = $mysqli->real_escape_string($nom);
      requete('reps',"UPDATE reps SET nom = '$nom' WHERE matiere = $mid AND parent = 0",$mysqli);
      // Modification de la table recents
      requete('recents',"UPDATE recents SET titre = ( SELECT CONCAT( IF(LENGTH(i.titre),i.titre,'Information'),' [$nom/',p.nom,']' )
                                                      FROM infos AS i LEFT JOIN pages AS p ON page=p.id WHERE i.id = recents.id )
                         WHERE type = 1 AND matiere = $mid",$mysqli);
      requete('recents',"UPDATE recents SET titre = CONCAT( SUBSTRING_INDEX(titre,' ',4), ' en $nom' ) WHERE type = 2 AND matiere = $mid",$mysqli);
      requete('recents',"UPDATE recents SET texte = ( SELECT CONCAT(ext,'|',taille,'|',d.parent,'|',GROUP_CONCAT( r.nom ORDER BY FIND_IN_SET(r.id,d.parents) SEPARATOR '/' ))
                                                      FROM docs AS d LEFT JOIN reps AS r ON FIND_IN_SET(r.id,d.parents) WHERE d.id = recents.id GROUP BY d.id )
                         WHERE type = 3 AND matiere = $mid",$mysqli);
      rss($mysqli,$mid,0);
    }
    if ( isset($requete['cle']) )  {
      $cle = $mysqli->real_escape_string($cle);
      requete('recents',"UPDATE recents SET lien = CONCAT('.?$cle&amp;n=',SUBSTRING_INDEX(lien,'&',-1)) WHERE type = 2 AND matiere = $mid",$mysqli);
      requete('recents',"UPDATE recents SET lien = ( SELECT CONCAT('.?$cle/',p.cle) FROM infos AS i LEFT JOIN pages AS p ON page=p.id WHERE i.id = recents.id )
                         WHERE type = 1 AND matiere = $mid",$mysqli);
    }
    // Si changement de protection 
    if ( isset($requete['docs']) )
      requete('reps','UPDATE reps SET protection ='.strrchr($requete['docs'],' ')." WHERE matiere = $mid AND parent = 0",$mysqli);
    if ( isset($requete['progcolles']) )  {
      $p = intval(strrchr($requete['progcolles'],' '));
      // Attention à ne pas dévoiler les programmes de colles cachés
      requete('recents',"UPDATE recents SET protection = $p WHERE type = 2 AND matiere = $mid".( ( $r['progcolles_protection'] < 32 ) ? ' AND protection < 32' : ''),$mysqli);
      rss($mysqli, $mid, $p, $r['progcolles_protection']);
    }
    // Retour
    exit($_SESSION['message'] = "{\"etat\":\"ok\",\"message\":\"La matière <em>${r['nom']}</em> a été modifiée.\",\"reload\":\"1\"}");
  }
  
  // Suppression
  if ( isset($_REQUEST['supprime']) )  {
    if ( $r['max'] == 1 )
      exit("{\"etat\":\"nok\",\"message\":\"La matière <em>${r['nom']}</em> n'a pas été supprimée. Il faut obligatoirement en garder au moins une.\"}");

    // Suppression physique des documents
    $resultat = $mysqli->query("SELECT lien FROM docs WHERE matiere = $mid");
    if ( $resultat->num_rows )  {
      while ( $s = $resultat->fetch_row() )
        exec("rm -rf documents/${s[0]}");
      $resultat->free();
    }
    // Suppression physique des documents transférés
    $resultat = $mysqli->query("SELECT lien FROM transferts WHERE matiere = $mid");
    if ( $resultat->num_rows )  {
      while ( $r = $resultat->fetch_row() )
        exec("rm -rf documents/${r[0]}");
      $resultat->free();
    }
    if ( requete('matieres',    "DELETE FROM matieres      WHERE id = $mid",$mysqli) 
      && requete('progcolles',  "DELETE FROM progcolles    WHERE matiere = $mid",$mysqli)
      && requete('cdt',         "DELETE FROM cdt           WHERE matiere = $mid",$mysqli)
      && requete('cdt-types',   "DELETE FROM `cdt-types`   WHERE matiere = $mid",$mysqli)
      && requete('cdt-seances', "DELETE FROM `cdt-seances` WHERE matiere = $mid",$mysqli)
      && requete('notescolles', "DELETE FROM notescolles   WHERE matiere = $mid",$mysqli)
      && requete('transdocs',   "DELETE FROM transdocs     WHERE transfert IN (SELECT id FROM transferts WHERE matiere = $mid)",$mysqli)
      && requete('transferts',  "DELETE FROM transferts    WHERE matiere = $mid",$mysqli)      
      && requete('reps',        "DELETE FROM reps          WHERE matiere = $mid",$mysqli)
      && requete('docs',        "DELETE FROM docs          WHERE matiere = $mid",$mysqli)
      && requete('infos',       "DELETE FROM infos         WHERE page IN (SELECT id FROM pages WHERE matiere = $mid)",$mysqli)
      && requete('pages',       "DELETE FROM pages         WHERE matiere = $mid",$mysqli)
      && requete('agenda',      "DELETE FROM agenda        WHERE matiere = $mid",$mysqli)
      && requete('recents',     "DELETE FROM recents       WHERE matiere = $mid",$mysqli)
      && requete('matieres',    "UPDATE matieres SET ordre = (ordre-1) WHERE ordre > ${r['ordre']}",$mysqli)
      && requete('utilisateurs',"UPDATE utilisateurs SET menuelements = '' WHERE FIND_IN_SET($mid,menumatieres)",$mysqli)
      && requete('utilisateurs',"UPDATE utilisateurs SET matieres = TRIM(TRAILING ',' FROM REPLACE(CONCAT(matieres,','),',$mid,',',')) ",$mysqli)
      && requete('utilisateurs',"UPDATE utilisateurs SET menumatieres = TRIM(TRAILING ',' FROM REPLACE(CONCAT(menumatieres,','),',$mid,',',')) ",$mysqli)
      && rss($mysqli,0,0) )
      exit("{\"etat\":\"ok\",\"message\":\"La matière <em>${r['nom']}</em> a été supprimée, ainsi que tous les contenus qui lui étaient associées.\"}");
    exit("{\"etat\":\"nok\",\"message\":\"La matière <em>${r['nom']}</em> n'a pas été supprimée. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');
  }

  // Suppression des programmes de colles d'une matière
  if ( isset($_REQUEST['supprime_progcolles']) )
    exit( requete('progcolles',"DELETE FROM progcolles WHERE matiere = $mid",$mysqli)
       && requete('matieres',"UPDATE matieres SET progcolles = 0 WHERE id = $mid",$mysqli)
       && requete('utilisateurs',"UPDATE utilisateurs SET menuelements = '' WHERE FIND_IN_SET($mid,menumatieres)",$mysqli)
       && requete('recents',"DELETE FROM recents WHERE type = 2 AND matiere = $mid",$mysqli)
       && rss($mysqli,$mid,$r['progcolles_protection'])
      ? "{\"etat\":\"ok\",\"message\":\"Les programmes de colle de la matière <em>${r['nom']}</em> ont été supprimés.\"}"
      : "{\"etat\":\"nok\",\"message\":\"Les programmes de colle de la matière <em>${r['nom']}</em> n'ont pas été supprimés. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');
  
  // Suppression du cahier de texte d'une matière
  if ( isset($_REQUEST['supprime_cdt']) )
    exit( requete('cdt',"DELETE FROM cdt WHERE matiere = $mid",$mysqli)
       && requete('cdt-type',"UPDATE `cdt-types` SET nb = 0 WHERE matiere = $mid",$mysqli)
       && requete('matieres',"UPDATE matieres SET cdt = 0 WHERE id = $mid",$mysqli)
       && requete('utilisateurs',"UPDATE utilisateurs SET menuelements = '' WHERE FIND_IN_SET($mid,menumatieres)",$mysqli)
      ? "{\"etat\":\"ok\",\"message\":\"Le cahier de texte de la matière <em>${r['nom']}</em> a été supprimé.\"}"
      : "{\"etat\":\"nok\",\"message\":\"Le cahier de texte de la matière <em>${r['nom']}</em> n'a pas été supprimé. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');

  // Suppression des notes d'une matière
  if ( isset($_REQUEST['supprime_notescolles']) )
    exit( requete('notescolles',"DELETE FROM notescolles WHERE matiere = $mid",$mysqli)
       && requete('matieres',"UPDATE matieres SET notescolles = 0 WHERE id = $mid",$mysqli)
       && requete('utilisateurs',"UPDATE utilisateurs SET menuelements = '' WHERE FIND_IN_SET($mid,menumatieres)",$mysqli)
      ? "{\"etat\":\"ok\",\"message\":\"Les notes de colles de la matière <em>${r['nom']}</em> ont été supprimées.\"}"
      : "{\"etat\":\"nok\",\"message\":\"Les notes de colles de la matière <em>${r['nom']}</em> n'ont pas été supprimées. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');

  // Suppression des transferts de documents personnels d'une matière
  if ( isset($_REQUEST['supprime_transferts']) )  {
    // Suppression physique
    $resultat = $mysqli->query("SELECT lien FROM transferts WHERE matiere = $mid");
    if ( $resultat->num_rows )  {
      while ( $r = $resultat->fetch_row() )
        exec("rm -rf documents/${r[0]}");
      $resultat->free();
    }
    exit( requete('transdocs',"DELETE FROM transdocs WHERE transfert IN (SELECT id FROM transferts WHERE matiere = $mid)",$mysqli)
       && requete('transferts',"DELETE FROM transferts WHERE matiere = $mid",$mysqli)
       && requete('matieres',"UPDATE matieres SET transferts = 0, transferts_protection = 32 WHERE id = $mid",$mysqli)
      ? "{\"etat\":\"ok\",\"message\":\"Les transferts de documents personnels de la matière <em>${r['nom']}</em> ont été supprimées.\"}"
      : "{\"etat\":\"nok\",\"message\":\"Les transferts de documents personnels de la matière <em>${r['nom']}</em> n'ont pas été supprimées. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');
  }

  // Suppression des documents d'une matière
  if ( isset($_REQUEST['supprime_docs']) )  {
    if ( requete('reps',"DELETE FROM reps WHERE matiere = $mid AND parent > 0",$mysqli)
      && requete('matieres',"UPDATE matieres SET docs = 0 WHERE id = $mid",$mysqli)
      && requete('recents',"DELETE FROM recents WHERE type = 3 AND matiere = $mid",$mysqli)
      && requete('utilisateurs',"UPDATE utilisateurs SET menuelements = '' WHERE FIND_IN_SET($mid,menumatieres) AND LOCATE('r',menuelements)",$mysqli)
      && rss($mysqli,$mid,0) )  {
      // Suppression physique
      $resultat = $mysqli->query("SELECT lien FROM docs WHERE matiere = $mid");
      if ( $resultat->num_rows )  {
        while ( $s = $resultat->fetch_row() )
          exec("rm -rf documents/${s[0]}");
        $resultat->free();
      }
      requete('docs',"DELETE FROM docs WHERE matiere = $mid",$mysqli);
      exit("{\"etat\":\"ok\",\"message\":\"Les répertoires et documents de la matière <em>${r['nom']}</em> ont été supprimés.\"}");
    }
    exit("{\"etat\":\"nok\",\"message\":\"Les documents de la matière <em>${r['nom']}</em> n'ont pas été supprimés. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');
  }
}

/////////////////////////////////////////////////////
// Modification des types d'événements de l'agenda //
/////////////////////////////////////////////////////
elseif ( ( $action == 'agenda-types' ) && ctype_digit($id = $_REQUEST['id'] ?? '') )  {

  // Vérification que l'identifiant est valide
  $resultat = $mysqli->query("SELECT nom, cle, ordre, (SELECT COUNT(*) FROM `agenda-types`) AS max FROM `agenda-types` WHERE id = $id");
  if ( !$resultat->num_rows )
    exit('{"etat":"nok","message":"Identifiant non valide"}');
  $r = $resultat->fetch_assoc();
  $resultat->free();

  // Récupération des préférences globales pour la page d'accueil
  $resultat = $mysqli->query('SELECT GROUP_CONCAT(val ORDER BY nom) FROM prefs WHERE nom LIKE \'agenda%max\'');
  list($datemax,$nbmax) = explode(',',$resultat->fetch_row()[0]);
  $resultat->free();

  // Traitement d'une modification
  if ( isset($_REQUEST['nom']) )  {
    $nom = ucfirst($mysqli->real_escape_string($_REQUEST['nom']));
    $cle = str_replace(' ','_',trim($mysqli->real_escape_string($_REQUEST['cle'] ?? '')));
    $couleur =  preg_filter('/^([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/','$1',$_REQUEST['couleur'] ?? '');
    if ( !$nom || !$cle || is_null($couleur) )
      exit('{"etat":"nok","message":"Le type d\'événements n\'a pas été modifié. Le nom, la clé et la couleur doivent être non vides."}');
    // Vérification que la clé n'existe pas déjà
    if ( $cle != $r['cle'] )  {
      $resultat = $mysqli->query("SELECT cle FROM `agenda-types` WHERE id != $id");
      if ( $resultat->num_rows )  {
        while ( $r = $resultat->fetch_assoc() )
          if ( $r['cle'] == $cle )
            exit('{"etat":"nok","message":"Le type d\'événements n\'a pas été modifié. Cette clé existe déjà et doit être unique."}');
        $resultat->free();
      }
    }
    $nbmax = min($nbmax, intval($_REQUEST['nbmax'] ?? ''));
    $datemax = min($datemax, intval($_REQUEST['datemax'] ?? ''));
    exit( requete('agenda-types',"UPDATE `agenda-types` SET nom = '$nom', cle = '$cle', couleur = '$couleur', index_nbmax = $nbmax, index_datemax = $datemax WHERE id = $id",$mysqli)
      ? '{"etat":"ok","message":"Le type d\'événements a été modifié."}'
      : '{"etat":"nok","message":"Le type d\'événements n\'a pas été modifié. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');
  }
  
  // Déplacement vers le haut
  if ( isset($_REQUEST['monte']) && ( $r['ordre'] > 1 ) )
    exit( requete('agenda-types',"UPDATE `agenda-types` SET ordre = (2*${r['ordre']}-1-ordre) WHERE ( ordre = ${r['ordre']} OR ordre = (${r['ordre']}-1) )",$mysqli)
      ? '{"etat":"ok","message":"Le type d\'événements a été déplacé."}'
      : '{"etat":"nok","message":"Le type d\'événements n\'a pas été déplacé. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}' );

  // Déplacement vers le bas
  if ( isset($_REQUEST['descend']) && ( $r['ordre'] < $r['max'] ) )
    exit( requete('agenda-types',"UPDATE `agenda-types` SET ordre = (2*${r['ordre']}+1-ordre) WHERE ( ordre = ${r['ordre']} OR ordre = (${r['ordre']}+1) )",$mysqli)
      ? '{"etat":"ok","message":"Le type d\'événements a été déplacé."}'
      : '{"etat":"nok","message":"Le type d\'événements n\'a pas été déplacé. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}' );

  // Suppression
  if ( isset($_REQUEST['supprime']) )  {
    $resultat = $mysqli->query("SELECT id FROM agenda WHERE type = $id LIMIT 1");
    if ( $resultat->num_rows )
      exit('{"etat":"nok","message":"Le type d\'événements n\'a pas été supprimé, car des événements correspondent à ce type."}');
    $resultat->free();
    exit( ( requete('agenda-types',"DELETE FROM `agenda-types` WHERE id = $id",$mysqli) 
         && requete('agenda-types',"UPDATE `agenda-types` SET ordre = (ordre-1) WHERE ordre > ${r['ordre']}",$mysqli) )
      ? '{"etat":"ok","message":"La suppression a été réalisée."}'
      : '{"etat":"nok","message":"Le type d\'événements n\'a pas été supprimé. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');
    }
}

//////////////////////////////////////////////
// Ajout d'un type d'événements de l'agenda //
//////////////////////////////////////////////
elseif ( $action == 'ajout-agenda-types' )  {
  
  // Vérification des valeurs données
  $nom = ucfirst($mysqli->real_escape_string($_REQUEST['nom'] ?? ''));
  $cle = str_replace(' ','_',trim($mysqli->real_escape_string($_REQUEST['cle'] ?? '')));
  $couleur =  preg_filter('/^([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/','$1',$_REQUEST['couleur'] ?? '');
  if ( !$nom || !$cle || is_null($couleur) )
    exit('{"etat":"nok","message":"Le type d\'événements n\'a pas été ajouté. Le nom, la clé et la couleur doivent être non vides."}');
  // Vérification que la clé n'existe pas déjà
  $resultat = $mysqli->query('SELECT cle FROM `agenda-types`');
  if ( $resultat->num_rows )  {
    while ( $r = $resultat->fetch_assoc() )
      if ( $r['cle'] == $cle )
        exit('{"etat":"nok","message":"Le type d\'événements n\'a pas été ajouté. Cette clé existe déjà et doit être unique."}');
    $resultat->free();
  }
  
  // Récupération des préférences globales pour la page d'accueil
  $resultat = $mysqli->query('SELECT GROUP_CONCAT(val ORDER BY nom) FROM prefs WHERE nom LIKE \'agenda%max\'');
  list($datemax,$nbmax) = explode(',',$resultat->fetch_row()[0]);
  $resultat->free();
  $nbmax = min($nbmax, intval($_REQUEST['nbmax'] ?? ''));
  $datemax = min($datemax, intval($_REQUEST['datemax'] ?? ''));
  
  // Écriture
  if ( requete('agenda-types',"INSERT INTO `agenda-types` SET nom = '$nom', cle = '$cle', couleur = '$couleur', index_nbmax = $nbmax, index_datemax = $datemax,
                               ordre = (SELECT max(t.ordre)+1 FROM `agenda-types` AS t)",$mysqli) )
    exit($_SESSION['message'] = '{"etat":"ok","message":"Le type d\'événements a été ajouté.","reload":"1"}');
  exit('{"etat":"nok","message":"Le type d\'événements n\'a pas été ajouté. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');
}


// Sans action
exit('{"etat":"nok","message":"Aucune action effectuée"}');
?>
