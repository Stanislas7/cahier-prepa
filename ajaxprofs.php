<?php
// Sécurité : script obligatoirement inclus par ajax.php
if ( !defined('OK') )  exit();

// Script d'exécution des commandes ajax pour l'administration
// Nécessite d'être professeur propriétaire de la page concernée en connexion normale
if ( ( $autorisation < 5 ) || !in_array($mid = intval($_REQUEST['matiere'] ?? $_REQUEST['id'] ?? -1), explode(',',$_SESSION['matieres'])) || !connexionlight() )
  exit( '{"etat":"nok","message":"Aucune action effectuée"}' );
$mysqli = connectsql(true);
// Spécifications pour les manipulations de caractères sur 2 octets (accents)
mb_internal_encoding('UTF-8');

///////////////////////////////////////////
// Modification des programmes de colles //
///////////////////////////////////////////
if ( ( $action == 'progcolles' ) && ctype_digit($sid = $_REQUEST['id'] ?? '') )  {

  // Vérification de l'identifiant de semaine et récupération des données
  $resultat = $mysqli->query("SELECT DATE_FORMAT(s.debut,'%e/%m') AS debut, c.id, c.cache, c.texte, IF(dispo,DATE_FORMAT(dispo,'%Y-%m-%d %H:%i'),0) AS dispo
                              FROM semaines AS s LEFT JOIN progcolles AS c ON c.semaine = s.id
                              WHERE s.id = $sid AND c.matiere = $mid");
  if ( !$resultat->num_rows )
    exit('{"etat":"nok","message":"Identifiant non valide"}');
  $r = $resultat->fetch_assoc();
  $resultat->free();
  
  // Traitement d'une modification unique (texte)
  if ( 'texte' == ( $_REQUEST['champ'] ?? '' ) )  {
    if ( !( $valeur = $mysqli->real_escape_string($_REQUEST['val'] ?? '') ) )
      exit("{\"etat\":\"nok\",\"message\":\"Le programme de colles de la semaine du ${r['debut']} n'a pas été modifié. Le texte doit être non vide.\"}");
    exit( requete('progcolles',"UPDATE progcolles SET texte = '$valeur' WHERE id = ${r['id']}",$mysqli) && recent($mysqli,2,$r['id'],array('texte'=>$valeur),isset($_REQUEST['publi']))
      ? "{\"etat\":\"ok\",\"message\":\"Le programme de colles de la semaine du ${r['debut']} a été modifié.\"}"
      : "{\"etat\":\"nok\",\"message\":\"Le programme de colles de la semaine du ${r['debut']} n'a pas été modifié. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');
  }

  // Modification de la date de disponibilité
  // Modifie la visibilité si besoin : si affichage différé, le programme est forcément visible
  if ( isset($_REQUEST['dispo']) )  {
    if ( $dispo = preg_filter('/(\d{2})\/(\d{2})\/(\d{4}) (\d{1,2})h(\d{2})/','$3-$2-$1 $4:$5',$_REQUEST['dispo']) )  {
      if ( strlen($dispo) == 15 )
        $dispo = substr($dispo,0,-4).'0'.substr($dispo,-4);
      if ( is_null($dispo) || ( $r['dispo'] == $dispo ) )
        exit("{\"etat\":\"nok\",\"message\":\"La date de disponibilité du programme de colles de la semaine du ${r['debut']} n'a pas été modifiée.\"}");
      // Modification uniquement si dispo est dans le futur
      if ( $dispo < date('Y-m-d H:i') ) 
        exit("{\"etat\":\"nok\",\"message\":\"La date de disponibilité du programme de colles de la semaine du ${r['debut']} n'a pas été modifiée car la valeur donnée est déjà passée.\"}");
      // Visibilité automatiquement rendue
      $modif = "dispo = '$dispo'";
      $modifrecent = array('publi'=>$dispo);
      if ( $r['cache'] )  {
        $modif .= ', cache = 0';
        $resultat = $mysqli->query("SELECT progcolles_protection FROM matieres WHERE id = $mid");
        $modifrecent['protection'] = $resultat->fetch_row()[0];
        $resultat->free();
      }
      if ( requete('progcolles',"UPDATE progcolles SET $modif WHERE id = ${r['id']}",$mysqli) && recent($mysqli,2,$r['id'],$modifrecent) )
        exit($_SESSION['message'] = "{\"etat\":\"ok\",\"message\":\"La date de disponibilité du programme de colles de la semaine du ${r['debut']} a été modifié.\",\"reload\":\"1\"}");
      exit("{\"etat\":\"nok\",\"message\":\"La date de disponibilité du programme de colles de la semaine du ${r['debut']} n'a pas été modifié. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');
    }
    // Suppression de l'affichage différé si existant et dans le futur -> affichage immédiat
    elseif ( $r['dispo'] > date('Y-m-d H:i') )  {
      if ( requete('progcolles',"UPDATE progcolles SET dispo = NOW() WHERE id = ${r['id']}",$mysqli) && recent($mysqli,2,$r['id'],array('publi'=>date('Y-m-d H:i'))) )
        exit($_SESSION['message'] = "{\"etat\":\"ok\",\"message\":\"Le programme de colles de la semaine du ${r['debut']} apparaît désormais pour les autres utilisateurs.\",\"reload\":\"1\"}");
      exit("{\"etat\":\"nok\",\"message\":\"La date de disponibilité du programme de colles de la semaine du ${r['debut']} n'a pas été supprimée. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');
    }
  }

  // Apparition pour les non-éditeurs, en fonction de la protection
  if ( isset($_REQUEST['montre']) )  {
    $resultat = $mysqli->query("SELECT progcolles_protection FROM matieres WHERE id = $mid");
    $protection = $resultat->fetch_row()[0];
    $resultat->free();
    exit( requete('progcolles',"UPDATE progcolles SET cache = 0 WHERE id = ${r['id']}",$mysqli) && recent($mysqli,2,$r['id'],array('protection'=>$protection))
        ? "{\"etat\":\"ok\",\"message\":\"Le programme de colles de la semaine du ${r['debut']} apparaît désormais pour les autres utilisateurs.\"}"
        : "{\"etat\":\"nok\",\"message\":\"Le programme de colles de la semaine du ${r['debut']} n'a pas été diffusé. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');
  }

  // Disparition pour les non-éditeurs
  // Supprime l'éventuel affichage différé
  if ( isset($_REQUEST['cache']) )  {
    if( requete('progcolles',"UPDATE progcolles SET cache = 1, dispo = 0 WHERE id = ${r['id']}",$mysqli) && recent($mysqli,2,$r['id'],array('protection'=>32)) )  {
      $message = "Le programme de colles de la semaine du ${r['debut']} n'apparaît plus désormais pour les autres utilisateurs mais est toujours disponible ici pour modification ou diffusion.";
      if ( $r['dispo'] > date('Y-m-d H:i') )
        exit($_SESSION['message'] = "{\"etat\":\"ok\",\"message\":\"$message\",\"reload\":\"1\"}");
      exit("{\"etat\":\"ok\",\"message\":\"$message\"}");
    }
    else
      exit("{\"etat\":\"nok\",\"message\":\"Le programme de colles de la semaine du ${r['debut']} n'a pas été caché. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');
  }

  // Suppression
  if ( isset($_REQUEST['supprime']) )  {
    if( requete('progcolles',"DELETE FROM progcolles WHERE id = ${r['id']}",$mysqli) && recent($mysqli,2,$r['id']) )
      exit($_SESSION['message'] = "{\"etat\":\"ok\",\"message\":\"Le programme de colles de la semaine du ${r['debut']} a été supprimé.\",\"reload\":\"1\"}");
    exit("{\"etat\":\"nok\",\"message\":\"Le programme de colles de la semaine du ${r['debut']} n'a pas été supprimé. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');
  }
}

////////////////////////////////////
// Ajout d'un programme de colles //
////////////////////////////////////
elseif ( ( $action == 'ajout-progcolle' ) && ctype_digit($sid = $_REQUEST['id'] ?? '') )  {

  // Vérification de l'identifiant de semaine et récupération des données
  // On vérifie aussi qu'on n'a pas déjà une saisie.
  $resultat = $mysqli->query("SELECT DATE_FORMAT(debut,'%e/%m')
                              FROM semaines LEFT JOIN progcolles ON semaine = semaines.id AND matiere = $mid
                              WHERE semaines.id = $sid AND colle AND progcolles.id IS NULL");
  if ( !$resultat->num_rows )
    exit('{"etat":"nok","message":"Identifiant non valide"}');
  $debut = $resultat->fetch_row()[0];
  $resultat->free();
  // Validation des données
  $cache = intval(isset($_REQUEST['cache']));
  if ( !( $texte = $mysqli->real_escape_string($_REQUEST['texte'] ?? '') ) )
    exit("{\"etat\":\"nok\",\"message\":\"Le programme de colles de la semaine du $debut n'a pas été ajouté. Le texte doit être non vide.\"}");
  // Vérification de l'affichage différé et de la date de disponibilité, seulement si dans le futur et si visible
  if ( !isset($_REQUEST['affdiff']) || $cache || is_null( $dispo = preg_filter('/(\d{2})\/(\d{2})\/(\d{4}) (\d{1,2})h(\d{2})/','$3-$2-$1 $4:$5',$_REQUEST['dispo'] ?? '') ) || ($dispo < date('Y-m-d H:i') ) )
    $dispo = 0;
  if( requete('progcolles',"INSERT INTO progcolles SET texte = '$texte', semaine = $sid, matiere = $mid, cache = $cache, dispo = '$dispo'",$mysqli) && recent($mysqli,2,$mysqli->insert_id,true) )  {
    // Mise à jour de la matière et du menu
    $resultat = $mysqli->query("UPDATE matieres SET progcolles = 1 WHERE id = $mid");
    if ( $mysqli->affected_rows )
      $mysqli->query("UPDATE utilisateurs SET menuelements='' WHERE FIND_IN_SET($mid,menumatieres)");
    exit($_SESSION['message'] = "{\"etat\":\"ok\",\"message\":\"Le programme de colles de la semaine du $debut a été ajouté.\",\"reload\":\"1\"}");
  }
  exit("{\"etat\":\"nok\",\"message\":\"Le programme de colles de la semaine du $debut n'a pas été ajouté. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');
}

/////////////////////////////////////////////////////////
// Modification ou ajout des éléments cahiers de texte //
/////////////////////////////////////////////////////////
elseif ( $action == 'cdt' )  {

  // Traitement d'une modification de propriétés/d'un ajout d'élément :
  // validation préliminaire
  if ( ctype_digit($tid = $_REQUEST['tid'] ?? '') )  {
    // Validation du jour de la semaine
    if ( is_null($jour = preg_filter('/(\d{2})\/(\d{2})\/(\d{4})/','$3-$2-$1',$_REQUEST['jour'] ?? '')) )
      exit('{"etat":"nok","message":"Le jour saisi n\'est une date valide."}');
    $resultat = $mysqli->query("SELECT id FROM semaines WHERE debut <= '$jour' AND debut >= SUBDATE('$jour',7) ORDER BY debut DESC LIMIT 1");
    if ( !$resultat->num_rows )
      exit('{"etat":"nok","message":"Le jour choisi ne se trouve pas dans l\'année scolaire. S\'il s\'agit d\'une erreur, il faut peut-être contacter l\'administrateur."}');
    $r = $resultat->fetch_assoc();
    $semaine = $r['id'];
    $resultat->free();
    // Validation du type de séance
    $resultat = $mysqli->query("SELECT id, deb_fin_pour FROM `cdt-types` WHERE id = $tid AND matiere = $mid");
    if ( !$resultat->num_rows )
      exit('{"etat":"nok","message":"Type de séance non valide."}');
    $r = $resultat->fetch_assoc();
    $resultat->free();
    // Validation des horaires
    $h_debut = $h_fin = '0:00';
    $pour = '0000/00/00';
    $demigroupe = 0;
    switch ( $r['deb_fin_pour'] )  {
      case 1: $h_fin = preg_filter('/(\d{1,2})h(\d{2})/','$1:$2',$_REQUEST['h_fin'] ?? '');
      case 0: $h_debut = preg_filter('/(\d{1,2})h(\d{2})/','$1:$2',$_REQUEST['h_debut'] ?? ''); $demigroupe = intval(1 == $_REQUEST['demigroupe'] ?? 0); break;
      case 2: $pour = preg_filter('/(\d{2})\/(\d{2})\/(\d{4})/','$3-$2-$1',$_REQUEST['pour'] ?? '');
      case 3: $demigroupe = intval(1 == $_REQUEST['demigroupe'] ?? 0);
      // Cas 4 et 5 : h_debut, h_fin et pour restent nuls (cf l'aide)
    }
  }

  // Vérification que l'identifiant est valide
  if ( ctype_digit($id = $_REQUEST['id'] ?? '') )  {
    $resultat = $mysqli->query("SELECT id, type, IF(dispo,DATE_FORMAT(dispo,'%Y-%m-%d %H:%i'),0) AS dispo FROM cdt WHERE id = $id AND matiere = $mid");
    if ( !$resultat->num_rows )
      exit('{"etat":"nok","message":"Identifiant de l\'élément de cahier de texte non valide"}');
    $r = $resultat->fetch_assoc();
    $resultat->free();

    // Traitement d'une modification de propriétés
    if ( isset($h_debut) && $h_debut && $h_fin && $pour )  {
      // Écriture dans la base de données
      if ( requete('cdt',"UPDATE cdt SET semaine = $semaine, jour = '$jour', h_debut = '$h_debut', h_fin = '$h_fin',
                          pour = '$pour', type = $tid, demigroupe = $demigroupe WHERE id = $id", $mysqli) )  {
        // Mise à jour du compte d'éléments si modification du type
        if ( $tid != $r['type'] )  {
          $mysqli->query("UPDATE `cdt-types` SET nb = nb-1 WHERE id = ${r['type']}");
          $mysqli->query("UPDATE `cdt-types` SET nb = nb+1 WHERE id = $tid");
        }
        exit($_SESSION['message'] = '{"etat":"ok","message":"Les propriétés de l\'élément du cahier de texte ont été modifiées.","reload":"1"}');
      }
      exit('{"etat":"nok","message":"Les propriétés de l\'élément du cahier de texte n\'ont pas été modifiées. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');
    }

    // Traitement d'une modification de texte
    if ( 'texte' == ( $_REQUEST['champ'] ?? '' ) )  {
      if ( !( $valeur = $mysqli->real_escape_string($_REQUEST['val'] ?? '') ) )
        exit('{"etat":"nok","message":"L\'élément du cahier de texte n\'a pas été modifié. Le texte doit être non vide."}');
      if ( requete('cdt',"UPDATE cdt SET texte = '$valeur' WHERE id = $id",$mysqli) )
        exit('{"etat":"ok","message":"Le texte de l\'élément du cahier de texte a été modifié."}');
      exit('{"etat":"nok","message":"Le texte de l\'élément du cahier de texte n\'a pas été modifié. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');
    }

    // Modification de la date de disponibilité seulement si différente
    if ( isset($_REQUEST['dispo']) )  {
      if ( $dispo = preg_filter('/(\d{2})\/(\d{2})\/(\d{4}) (\d{1,2})h(\d{2})/','$3-$2-$1 $4:$5',$_REQUEST['dispo']) )  {
        if ( strlen($dispo) == 15 )
          $dispo = substr($dispo,0,-4).'0'.substr($dispo,-4);
        if ( is_null($dispo) || ( $r['dispo'] == $dispo ) )
          exit('{"etat":"nok","message":"La date de disponibilité de l\'élément du cahier de texte n\'a pas été modifiée."}');
        // Modification uniquement si dispo est dans le futur
        if ( $dispo < date('Y-m-d H:i') ) 
          exit('{"etat":"nok","message":"La date de disponibilité de l\'élément du cahier de texte n\'a pas été modifiée car elle est déjà passée."}');
        if ( requete('cdt',"UPDATE cdt SET dispo = '$dispo', cache = 0 WHERE id = $id",$mysqli) )
          exit($_SESSION['message'] = '{"etat":"ok","message":"La date de disponibilité de l\'élément du cahier de texte a été modifiée.","reload":"1"}');
        exit('{"etat":"nok","message":"La date de disponibilité de l\'élément de cahier de texte n\'a pas été modifiée. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');
      }
      // Suppression de l'affichage différé si existant et dans le futur
      elseif ( $r['dispo'] > date('Y-m-d H:i') )  {
        if ( requete('cdt',"UPDATE cdt SET dispo = NOW() WHERE id = $id",$mysqli) )
          exit($_SESSION['message'] = '{"etat":"ok","message":"L\'élément du cahier de texte apparaît désormais pour les autres utilisateurs.","reload":"1"}');
        exit('{"etat":"nok","message":"La date de disponibilité de l\'élément de cahier de texte n\'a pas été supprimée. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');
      }
    }
    
    // Positionnement "caché" (n'apparaît pas sur la partie publique)
    if ( isset($_REQUEST['cache']) )
      exit( requete('cdt',"UPDATE cdt SET cache = 1, dispo = 0 WHERE id = $id",$mysqli)
          ? '{"etat":"ok","message":"L\'élément du cahier de texte n\'apparaît plus sur la partie publique mais est toujours disponible ici pour modification ou diffusion."}'
          : '{"etat":"nok","message":"L\'élément du cahier de texte n\'a pas été caché. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');
    
    // Positionnement "montré" (apparaît sur la partie publique)
    if ( isset($_REQUEST['montre']) )  {
      $message = ( $r['dispo'] > date('Y-m-d H:i') )
               ? 'L\'élément du cahier de texte apparaîtra pour les autres utilisateurs à partir du '.preg_filter('/(\d{4})-(\d{2})-(\d{2}) (\d{1,2}):(\d{2}).*/','$3/$2/$1 à $4h$5',$r['dispo']).'.'
               : 'L\'élément du cahier de texte apparaît désormais pour les autres utilisateurs.';
      exit( requete('cdt',"UPDATE cdt SET cache = 0 WHERE id = $id",$mysqli)
          ? "{\"etat\":\"ok\",\"message\":\"$message\"}"
          : '{"etat":"nok","message":"L\'élément du cahier de texte n\'a pas été diffusé. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');
    }
    
    // Suppression
    if ( isset($_REQUEST['supprime']) )
      exit( requete('cdt',"DELETE FROM cdt WHERE id = $id",$mysqli) && requete('cdt-types',"UPDATE `cdt-types` SET nb = nb-1 WHERE id = ${r['type']}",$mysqli)
          ? '{"etat":"ok","message":"Suppression réalisée"}'
          : '{"etat":"nok","message":"L\'élément du cahier de texte n\'a pas été supprimée. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');
  }
  
  // Nouvel élément du cahier de texte
  elseif ( isset($demigroupe) )  {
    // Vérification des valeurs saisies
    $cache = intval(isset($_REQUEST['cache']));
    if ( !($texte = $mysqli->real_escape_string($_REQUEST['texte'] ?? '')) )
      exit('{"etat":"nok","message":"L\'élément du cahier de texte n\'a pas été ajouté. Le texte doit être non vide."}');
    if ( is_null($h_debut) )
      exit('{"etat":"nok","message":"L\'élément du cahier de texte n\'a pas été ajouté. L\'horaire de début doit être non vide."}');
    if ( is_null($h_fin) )
      exit('{"etat":"nok","message":"L\'élément du cahier de texte n\'a pas été ajouté. L\'horaire de fin doit être non vide."}');
    if ( is_null($pour) )
      exit('{"etat":"nok","message":"L\'élément du cahier de texte n\'a pas été ajouté. La date d\'échéance doit être non vide."}');
    // Vérification de l'affichage différé et de la date de disponibilité, seulement si dans le futur et si visible
    if ( !isset($_REQUEST['affdiff']) || $cache || is_null( $dispo = preg_filter('/(\d{2})\/(\d{2})\/(\d{4}) (\d{1,2})h(\d{2})/','$3-$2-$1 $4:$5',$_REQUEST['dispo'] ?? '') ) || ($dispo < date('Y-m-d H:i') ) )
      $dispo = 0;
    // Écriture dans la base de données
    if ( requete('cdt',"INSERT INTO cdt SET matiere = $mid, semaine = $semaine, jour = '$jour', h_debut = '$h_debut', h_fin = '$h_fin',
                                            pour = '$pour', type = $tid, texte = '$texte', demigroupe = $demigroupe, cache = $cache, dispo = '$dispo'", $mysqli)
         && requete('cdt-types',"UPDATE `cdt-types` SET nb = nb+1 WHERE id = $tid",$mysqli) )  {
      // Mise à jour de la matière et du menu
      $resultat = $mysqli->query("UPDATE matieres SET cdt = 1 WHERE id = $mid");
      if ( $mysqli->affected_rows )
        $mysqli->query("UPDATE utilisateurs SET menuelements='' WHERE FIND_IN_SET($mid,menumatieres)");
      exit($_SESSION['message'] = '{"etat":"ok","message":"L\'élément du cahier de texte a été ajouté.","reload":"1"}');
    }
    exit('{"etat":"nok","message":"L\'élément du cahier de texte n\'a pas été ajouté. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');
  }
}

///////////////////////////////////////////////////////////
// Modification des types de séance des cahiers de texte //
///////////////////////////////////////////////////////////
elseif ( ( $action == 'cdt-types' ) && ctype_digit($id = $_REQUEST['id'] ?? '') )  {
  
  // Vérification que l'identifiant est valide
  $resultat = $mysqli->query("SELECT ordre, titre, nb, cle, (SELECT COUNT(*) FROM `cdt-types` WHERE matiere = $mid) AS max FROM `cdt-types` AS c WHERE id = $id AND matiere = $mid");
  if ( !$resultat->num_rows )
    exit('{"etat":"nok","message":"Identifiant du type d\'élément de cahier de texte non valide"}');
  $r = $resultat->fetch_assoc();
  $resultat->free();

  // Traitement d'une modification
  if ( in_array($deb_fin_pour = intval($_REQUEST['deb_fin_pour'] ?? -1),array(0,1,2,3,4,5)) )  {
    $titre = mb_strtoupper(mb_substr($titre = strip_tags(trim($mysqli->real_escape_string($_REQUEST['titre'] ?? ''))),0,1)).mb_substr($titre,1);
    $cle = str_replace(' ','_',trim($mysqli->real_escape_string($_REQUEST['cle'] ?? '')));
    if ( !$titre || !$cle )
      exit("{\"etat\":\"nok\",\"message\":\"Le type de séance <em>${r['titre']}</em> n'a pas été modifié. Le texte et la clé doivent être non vides.\"}");
    if ( $cle != $r['cle'] )  {
      // Vérification que la clé n'existe pas déjà
      $resultat = $mysqli->query("SELECT cle FROM `cdt-types` WHERE id != $id AND matiere = $mid");
      if ( $resultat->num_rows )  {
        while ( $s = $resultat->fetch_assoc() )
          if ( $s['cle'] == $cle )
            exit("{\"etat\":\"nok\",\"message\":\"Le type de séance <em>${r['titre']}</em> n'a pas été modifié. Cette clé existe déjà et doit être unique.\"}");
        $resultat->free();
      }
    }
    if ( requete('cdt-types',"UPDATE `cdt-types` SET titre = '$titre', cle = '$cle', deb_fin_pour = $deb_fin_pour WHERE id = $id",$mysqli) )
      exit($_SESSION['message'] = "{\"etat\":\"ok\",\"message\":\"Le type de séance <em>${r['titre']}</em> a été modifié.\",\"reload\":\"1\"}");
    exit("{\"etat\":\"nok\",\"message\":\"Le type de séance <em>${r['titre']}</em> n'a pas été modifié. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');
  }
  
  // Déplacement vers le haut
  if ( isset($_REQUEST['monte']) && ( $r['ordre'] > 1 ) )
    exit( requete('cdt-types',"UPDATE `cdt-types` SET ordre = (2*${r['ordre']}-1-ordre) WHERE ( ordre = ${r['ordre']} OR ordre = (${r['ordre']}-1) ) AND matiere = $mid",$mysqli)
        ? "{\"etat\":\"ok\",\"message\":\"Le déplacement du type de séance <em>${r['titre']}</em> a été réalisé.\"}"
        : "{\"etat\":\"nok\",\"message\":\"Le type de séance <em>${r['titre']}</em> n'a pas été déplacé. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');

  // Déplacement vers le bas
  if ( isset($_REQUEST['descend']) && ( $r['ordre'] < $r['max'] ) )
    exit( requete('cdt-types',"UPDATE `cdt-types` SET ordre = (2*${r['ordre']}+1-ordre) WHERE ( ordre = ${r['ordre']} OR ordre = (${r['ordre']}+1) ) AND matiere = $mid",$mysqli)
        ? "{\"etat\":\"ok\",\"message\":\"Le déplacement du type de séance <em>${r['titre']}</em> a été réalisé.\"}"
        : "{\"etat\":\"nok\",\"message\":\"Le type de séance <em>${r['titre']}</em> n'a pas été déplacé. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');

  // Suppression
  if ( isset($_REQUEST['supprime']) )  {
    if ( $r['max'] == 1 )
      exit("{\"etat\":\"ok\",\"message\":\"Le type de séance <em>${r['nom']}</em> n'a pas été supprimé. Il faut obligatoirement en garder au moins un.\"}");
    exit( ( requete('cdt',"DELETE FROM cdt WHERE type = $id",$mysqli) && requete('cdt-types',"DELETE FROM `cdt-types` WHERE id = $id",$mysqli)
            && requete('cdt-types',"UPDATE `cdt-types` SET ordre = (ordre-1) WHERE ordre > ${r['ordre']} AND matiere = $mid",$mysqli) )
        ? "{\"etat\":\"ok\",\"message\":\"Le type de séance <em>${r['titre']}</em> a été supprimé.\"}"
        : "{\"etat\":\"nok\",\"message\":\"Le type de séance <em>${r['titre']}</em> n'a pas été supprimé. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');
  }
}

////////////////////////////////////////////////////
// Ajout d'un type de séance des cahiers de texte //
////////////////////////////////////////////////////
elseif ( ( $action == 'ajout-cdt-type' ) && in_array($deb_fin_pour = intval($_REQUEST['deb_fin_pour'] ?? -1),array(0,1,2,3,4,5)) )  {
  $titre = mb_strtoupper(mb_substr($titre = strip_tags(trim($mysqli->real_escape_string($_REQUEST['titre'] ?? ''))),0,1)).mb_substr($titre,1);
  $cle = str_replace(' ','_',trim($mysqli->real_escape_string($_REQUEST['cle'] ?? '')));
  if ( !$titre || !$cle )
    exit('{"etat":"nok","message":"Le type de séance n\'a pas été ajouté. Le texte et la clé doivent être non vides."}');
  // Vérification que la clé n'existe pas déjà
  $resultat = $mysqli->query("SELECT cle FROM `cdt-types` WHERE matiere = $mid");
  if ( $resultat->num_rows )  {
    while ( $r = $resultat->fetch_assoc() )
      if ( $r['cle'] == $cle )
        exit('{"etat":"nok","message":"Le type de séance n\'a pas été ajouté. Cette clé existe déjà et doit être unique."}');
    $resultat->free();
  }
  // Écriture
  if ( requete('cdt-types',"INSERT INTO `cdt-types` SET matiere = $mid, titre = '$titre', cle = '$cle', deb_fin_pour = $deb_fin_pour,
                              ordre = (SELECT max(ct.ordre)+1 FROM `cdt-types` AS ct WHERE ct.matiere = $mid)",$mysqli) )
    exit($_SESSION['message'] = "{\"etat\":\"ok\",\"message\":\"Le type de séance <em>$titre</em> a été ajouté.\",\"reload\":\"1\"}");
  exit("{\"etat\":\"nok\",\"message\":\"Le type de séance <em>$titre</em> n'a pas été ajouté. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');
}

//////////////////////////////////////////////////////
// Modification des raccourcis des cahiers de texte //
//////////////////////////////////////////////////////
elseif ( ( $action == 'cdt-raccourcis' ) && ctype_digit($id = $_REQUEST['id'] ?? '') )  {
  
  // Vérification que l'identifiant est valide
  $resultat = $mysqli->query("SELECT ordre, nom, (SELECT COUNT(*) FROM `cdt-seances` WHERE matiere = $mid) AS max FROM `cdt-seances` AS c WHERE id = $id AND matiere = $mid");
  if ( !$resultat->num_rows )
    exit('{"etat":"nok","message":"Identifiant du raccourci de cahier de texte non valide"}');
  $r = $resultat->fetch_assoc();
  $resultat->free();

  // Traitement d'une modification
  if ( ctype_digit($type = $_REQUEST['type'] ?? '') && isset($_REQUEST['template']) && ctype_digit( $jour = $_REQUEST['jour'] ?? 1 ) )  {
    if ( !( $nom = $mysqli->real_escape_string(trim($_REQUEST['nom'] ?? '')) ) )
      exit("{\"etat\":\"nok\",\"message\":\"Le raccourci de séance <em>${r['nom']}</em> n'a pas été modifié. Le nom doit être non vide.\"}");
    $template = trim($mysqli->real_escape_string($_REQUEST['template']));
    // Validation du type de séance
    $resultat = $mysqli->query("SELECT deb_fin_pour FROM `cdt-types` WHERE id = $type AND matiere = $mid");
    if ( !$resultat->num_rows )
      exit("{\"etat\":\"nok\",\"message\":\"Le raccourci de séance <em>${r['nom']}</em> n'a pas été modifié. Le type de séance n'est pas valide.\"}");
    $s = $resultat->fetch_assoc();
    $resultat->free();
    // Validation des horaires
    $h_debut = $h_fin = '0:00';
    $demigroupe = 0;
    switch ( $s['deb_fin_pour'] )  {
      case 1: $h_fin = preg_filter('/(\d{1,2})h(\d{2})/','$1:$2',$_REQUEST['h_fin'] ?? $h_debut);
      case 0: $h_debut = preg_filter('/(\d{1,2})h(\d{2})/','$1:$2',$_REQUEST['h_debut'] ?? $h_fin);
      case 2: 
      case 3: $demigroupe = intval($_REQUEST['demigroupe'] == 1);
      // Cas 4 et 5 : h_debut, h_fin et pour restent nuls (cf l'aide)
    }
    // Écriture
    if ( $h_debut && $h_fin )  {
      if ( requete('cdt-seances',"UPDATE `cdt-seances` SET nom = '$nom', jour = $jour, h_debut = '$h_debut',
                                  h_fin = '$h_fin', type = $type, demigroupe = $demigroupe, template = '$template' WHERE id = $id",$mysqli) )
        exit($_SESSION['message'] = "{\"etat\":\"ok\",\"message\":\"Le raccourci de séance <em>${r['nom']}</em> a été modifié.\",\"reload\":\"1\"}");
      exit("{\"etat\":\"nok\",\"message\":\"Le raccourci de séance <em>${r['nom']}</em> n'a pas été modifié. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');
    }
  }
  
  // Déplacement vers le haut
  if ( isset($_REQUEST['monte']) && ( $r['ordre'] > 1 ) )
    exit( requete('cdt-seances',"UPDATE `cdt-seances` SET ordre = (2*${r['ordre']}-1-ordre) WHERE ( ordre = ${r['ordre']} OR ordre = (${r['ordre']}-1) ) AND matiere = $mid",$mysqli)
        ? "{\"etat\":\"ok\",\"message\":\"Le déplacement du raccourci de séance <em>${r['nom']}</em> a été réalisé.\"}"
        : "{\"etat\":\"nok\",\"message\":\"Le raccourci de séance <em>${r['nom']}</em> n'a pas été déplacé. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');

  // Déplacement vers le bas
  if ( isset($_REQUEST['descend']) && ( $r['ordre'] < $r['max'] ) )
    exit( requete('cdt-seances',"UPDATE `cdt-seances` SET ordre = (2*${r['ordre']}+1-ordre) WHERE ( ordre = ${r['ordre']} OR ordre = (${r['ordre']}+1) ) AND matiere = $mid",$mysqli)
        ? "{\"etat\":\"ok\",\"message\":\"Le déplacement du raccourci de séance <em>${r['nom']}</em> a été réalisé.\"}"
        : "{\"etat\":\"nok\",\"message\":\"Le raccourci de séance <em>${r['nom']}</em> n'a pas été déplacé. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');

  // Suppression
  if ( isset($_REQUEST['supprime']) )
    exit( ( requete('cdt-seances',"DELETE FROM `cdt-seances` WHERE id = $id",$mysqli)
            && requete('cdt-seances',"UPDATE `cdt-seances` SET ordre = (ordre-1) WHERE ordre > ${r['ordre']} AND matiere = $mid",$mysqli) )
        ? "{\"etat\":\"ok\",\"message\":\"Le raccourci de séance <em>${r['nom']}</em> a été supprimé.\"}"
        : "{\"etat\":\"nok\",\"message\":\"Le raccourci de séance <em>${r['nom']}</em> n'a pas été supprimé. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');
}

///////////////////////////////////////////////
// Ajout d'un raccourci des cahiers de texte //
///////////////////////////////////////////////
elseif ( ( $action == 'ajout-cdt-raccourci' ) && ctype_digit($type = $_REQUEST['type'] ?? '') && isset($_REQUEST['template']) && ctype_digit( $jour = $_REQUEST['jour'] ?? 1 ) )  {
  if ( !( $nom = $mysqli->real_escape_string(trim($_REQUEST['nom'] ?? '')) ) )
    exit('{"etat":"nok","message":"Le raccourci de séance n\'a pas été ajouté. Le nom doit être non vide."}');
  $template = trim($mysqli->real_escape_string($_REQUEST['template']));
  // Validation du type de séance
  $resultat = $mysqli->query("SELECT id, deb_fin_pour FROM `cdt-types` WHERE id = $type AND matiere = $mid");
  if ( !$resultat->num_rows )
    exit('{"etat":"nok","message":"Le raccourci de séance n\'a pas été ajouté. Le type de séance n\'est pas valide."}');
  $r = $resultat->fetch_assoc();
  $resultat->free();
  // Validation des horaires
  $h_debut = $h_fin = '0:00';
  $demigroupe = 0;
  switch ( $r['deb_fin_pour'] )  {
    case 1: $h_fin = preg_filter('/(\d{1,2})h(\d{2})/','$1:$2',$_REQUEST['h_fin'] ?? $h_debut);
    case 0: $h_debut = preg_filter('/(\d{1,2})h(\d{2})/','$1:$2',$_REQUEST['h_debut'] ?? $h_fin);
    case 2: 
    case 3: $demigroupe = intval($_REQUEST['demigroupe'] == 1);
    // Cas 4 et 5 : h_debut, h_fin et pour restent nuls (cf l'aide)
  }
  // Écriture
  if ( requete('cdt-seances',"INSERT INTO `cdt-seances` SET matiere = $mid, nom = '$nom', jour = $jour,
                              ordre = (SELECT IFNULL(max(cs.ordre)+1,1) FROM `cdt-seances` AS cs WHERE cs.matiere = $mid),
                              h_debut = '$h_debut', h_fin = '$h_fin', type = $type, demigroupe = $demigroupe, template = '$template'",$mysqli) )
    exit($_SESSION['message'] = "{\"etat\":\"ok\",\"message\":\"Le raccourci de séance <em>$nom</em> a été ajouté.\",\"reload\":\"1\"}");
  exit('{"etat":"nok","message":"Le raccourci de séance n\'a pas été ajouté. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');
}

//////////////////////////////////////////////////
// Ajout d'un transfert de documents personnels //
//////////////////////////////////////////////////
elseif ( ( $action == 'ajout-transfert' ) && in_array($sens = intval($_REQUEST['sens'] ?? -1),array(0,1)) && ctype_digit($acces = $_REQUEST['accestransfert'] ?? '') && isset($_REQUEST['dispo']) && isset($_REQUEST['indications']) )  {
  
  // Vérification que les transferts sans matière sont autorisés si besoin
  if ( !$mid )  {
    $resultat = $mysqli->query('SELECT val FROM prefs WHERE nom = "transferts_general"');
    $val = $resultat->fetch_row()[0];
    $resultat->free();
    if ( $val == 2 )
      exit('{"etat":"nok","message":"Action non autorisée"}');
  }
  
  // Validation des données
  $titre = htmlspecialchars(trim($mysqli->real_escape_string($_REQUEST['titre'] ?? '')));
  $prefixe = str_replace(array('\\','/'),array('-','-'),trim($mysqli->real_escape_string($_REQUEST['prefixe'] ?? '')));
  $indications = trim($mysqli->real_escape_string($_REQUEST['indications']));
  // Validation de $acces : 2(C)+4(L)+8(P)
  $type = $sens + ( $acces & 14 );
  if ( !$titre || !$prefixe )
    exit('{"etat":"nok","message":"Le transfert de documents n\'a pas été ajouté. Le titre et le préfixe doivent être non vides."}');
  // Vérification de la date limite d'envoi
  if ( !isset($_REQUEST['echeance']) || is_null($deadline = preg_filter('/(\d{2})\/(\d{2})\/(\d{4}) (\d{1,2})h(\d{2})/','$3-$2-$1 $4:$5',$_REQUEST['deadline'] ?? '')) )
    $deadline = '2100-01-01 00:00';
  if ( strlen($deadline) == 15 )
    $deadline = substr($deadline,0,-4).'0'.substr($deadline,-4);
  // Vérification de l'affichage différé et de la date de disponibilité, seulement si dans le futur
  if ( !isset($_REQUEST['affdiff']) || is_null( $dispo = preg_filter('/(\d{2})\/(\d{2})\/(\d{4}) (\d{1,2})h(\d{2})/','$3-$2-$1 $4:$5',$_REQUEST['dispo']) ) || ($dispo < date('Y-m-d H:i') ) )
    $dispo = 0;
  else  {
    if ( strlen($dispo) == 15 )
      $dispo = substr($dispo,0,-4).'0'.substr($dispo,-4);
    if ( $deadline < $dispo )
      exit('{"etat":"nok","message":"Le transfert de documents n\'a pas été ajouté. La date limite doit être après la date d\'affichage."}');
  }
  // Création du répertoire aléatoire d'accueil
  $lien = substr(sha1(mt_rand()),0,14);
  while ( is_dir("documents/t$lien") )
    $lien = substr(sha1(mt_rand()),0,14);
  mkdir("documents/t$lien");
  if ( requete('transferts',"INSERT INTO transferts SET matiere = $mid, type = $type, deadline = '$deadline', titre = '$titre', prefixe = '$prefixe', lien = 't$lien', indications = '$indications', dispo = '$dispo'",$mysqli) )  {
    // Protection à ajuster en fonction des transferts existants
    // Idem ajaxprofsadmin.php, section matières et ajaxadmin.php, section prefsglobales
    if ( $mid )
      requete('matieres',"UPDATE matieres SET transferts = 1, transferts_protection = IFNULL( (SELECT 32-(BIT_OR(type)<<1|2) FROM transferts WHERE matiere = $mid), 32) WHERE id = $mid",$mysqli);
    else  {
      requete('prefs','UPDATE prefs SET val = 1 WHERE nom = \'transferts_general\'',$mysqli);
      requete('prefs','UPDATE prefs SET val = IFNULL( (SELECT 16-(BIT_OR(type)<<1|2) FROM transferts WHERE matiere = 0), 32) WHERE nom = \'transferts_general_protection\'',$mysqli);
    }
    if ( $mysqli->affected_rows )
      requete('utilisateurs',"UPDATE utilisateurs SET menuelements='' WHERE FIND_IN_SET($mid,menumatieres)",$mysqli);
    exit($_SESSION['message'] = '{"etat":"ok","message":"Le transfert de documents a été ajouté.","reload":"2"}');
  }
  exit('{"etat":"nok","message":"Le transfert de documents n\'a pas été ajouté. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');
}

/////////////////////////////////////////////////////////
// Modification d'un transfert de documents personnels //
/////////////////////////////////////////////////////////
elseif ( ( $action == 'transferts' ) && ctype_digit($id = $_REQUEST['id'] ?? '') )  {
  
  // Vérification que l'identifiant est valide
  $resultat = $mysqli->query("SELECT titre, prefixe, type, IF(deadline,DATE_FORMAT(deadline,'%Y-%m-%d %H:%i'),0) AS deadline, indications, IF(dispo,DATE_FORMAT(dispo,'%Y-%m-%d %H:%i'),0) AS dispo, lien FROM transferts WHERE id = $id AND matiere = $mid");
  if ( !$resultat->num_rows )
    exit('{"etat":"nok","message":"Identifiant non valide"}');
  $r = $resultat->fetch_assoc();
  $resultat->free();

  // Traitement d'une modification
  if ( isset($_REQUEST['indications']) && isset($_REQUEST['dispo']) )  {
    $titre = htmlspecialchars(trim($mysqli->real_escape_string($_REQUEST['titre'] ?? '')));
    $prefixe = str_replace(array('\\','/'),array('-','-'),trim($mysqli->real_escape_string($_REQUEST['prefixe'] ?? '')));
    $indications = trim($mysqli->real_escape_string($_REQUEST['indications']));
    if ( !$titre || !$prefixe )
      exit('{"etat":"nok","message":"Le transfert de documents n\'a pas été modifié. Le titre et le préfixe doivent être non vides."}');
    // Vérification de la date limite d'envoi
    if ( !isset($_REQUEST['echeance']) || is_null($deadline = preg_filter('/(\d{2})\/(\d{2})\/(\d{4}) (\d{1,2})h(\d{2})/','$3-$2-$1 $4:$5',$_REQUEST['deadline'] ?? '')) )
      $deadline = '2100-01-01 00:00';
    if ( strlen($deadline) == 15 )
      $deadline = substr($deadline,0,-4).'0'.substr($deadline,-4);
    // Vérification de l'affichage différé et de la date de disponibilité, seulement si dans le futur
    if ( !isset($_REQUEST['affdiff']) || is_null( $dispo = preg_filter('/(\d{2})\/(\d{2})\/(\d{4}) (\d{1,2})h(\d{2})/','$3-$2-$1 $4:$5',$_REQUEST['dispo']) ) || ($dispo < date('Y-m-d H:i') ) )
      $dispo = 0;
    else  {
      if ( strlen($dispo) == 15 )
        $dispo = substr($dispo,0,-4).'0'.substr($dispo,-4);
      if ( $deadline < $dispo )
        exit('{"etat":"nok","message":"Le transfert de documents n\'a pas été modifié. La date limite d\'envoi doit être ultérieure à la date d\'affichage."}');
    }
    // Écriture
    if ( ( $titre != $r['titre'] ) || ( $prefixe != $r['prefixe'] ) || ( $indications != $mysqli->real_escape_string($r['indications']) ) || ( $deadline != $r['deadline'] ) || ( $dispo != $r['dispo'] ) )  {
      if ( !requete('transferts',"UPDATE transferts SET deadline = '$deadline', dispo = '$dispo', titre = '$titre', prefixe = '$prefixe', indications = '$indications' WHERE id = $id",$mysqli) )
        exit('{"etat":"nok","message":"Le transfert de documents n\'a pas été modifié. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');
      exit($_SESSION['message'] = "{\"etat\":\"ok\",\"message\":\"Le transfert de documents <em>${r['prefixe']}</em> a été modifié.\",\"reload\":\"1\"}");
    }
    exit("{\"etat\":\"nok\",\"message\":\"Le transfert du document <em>${r['prefixe']}</em> n'a pas été modifié. Aucune modification demandée.\"}");
  }
  
  // Suppression
  elseif ( isset($_REQUEST['supprime']) )  {
    $prefixe = $r['prefixe'];
    if ( !requete('transferts',"DELETE FROM transferts WHERE id = $id",$mysqli) || !requete('transdocs',"DELETE FROM transdocs WHERE transfert = $id",$mysqli) )
      exit("{\"etat\":\"nok\",\"message\":\"Le transfert de documents <em>$prefixe</em> n'a pas été supprimé. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'».');
    // Suppression physique
    exec("rm -rf documents/${r['lien']}");
    // Protection à ajuster en fonction des transferts existants
    // Idem ajaxprofsadmin.php, section matières et ajaxadmin.php, section prefsglobales
    if ( $mid )
      requete('matieres',"UPDATE matieres SET transferts = IF( (SELECT id FROM transferts WHERE matiere = $mid LIMIT 1) ,1,0 ), transferts_protection = IFNULL( (SELECT 32-(BIT_OR(type)<<1|2) FROM transferts WHERE matiere = $mid), 32) WHERE id = $mid",$mysqli);
    else  {
      requete('prefs','UPDATE prefs SET val = IF( (SELECT id FROM transferts WHERE matiere = 0 LIMIT 1) ,1,0 ) WHERE nom = \'transferts_general\'',$mysqli);
      requete('prefs','UPDATE prefs SET val = IFNULL( (SELECT 16-(BIT_OR(type)<<1|2) FROM transferts WHERE matiere = 0), 32) WHERE nom = \'transferts_general_protection\'',$mysqli);
    }
    if ( $mysqli->affected_rows )
      requete('utilisateurs',"UPDATE utilisateurs SET menuelements='' WHERE FIND_IN_SET($mid,menumatieres)",$mysqli);
    // Rechargement si on supprime le dernier transfert
    $resultat = $mysqli->query("SELECT id FROM transferts WHERE matiere = $mid LIMIT 1");
    if ( $resultat->num_rows )  {
      $resultat->free();
      exit("{\"etat\":\"ok\",\"message\":\"Le transfert de documents <em>$prefixe</em> a été supprimé.\"}");
    }
    exit($_SESSION['message'] = "{\"etat\":\"ok\",\"message\":\"Le transfert de documents <em>$prefixe</em> a été supprimé.\",\"reload\":\"1\"}");
  }
}

///////////////////////////////
// Modification des matières //
///////////////////////////////
elseif ( $action == 'prefsmatiere' )  {
  
  $resultat = $mysqli->query("SELECT nom, progcolles_protection, cdt_protection, dureecolles, heurescolles FROM matieres WHERE id = $mid");
  $r = $resultat->fetch_assoc();
  $resultat->free();
  
  // Gestion de la durée de colle, notescolles-gestion.php
  if ( ctype_digit($dureecolles = $_REQUEST['dureecolles'] ?? '') )  {
    $heurescolles = intval(isset($_REQUEST['heurescolles']));
    if ( ( $dureecolles == $r['dureecolles'] ) && ( $heurescolles = $r['heurescolles'] ) )
      exit("{\"etat\":\"nok\",\"message\":\"Les paramètres de calcul automatique des durées de colle en <em>${r['nom']}</em> n'ont pas été modifiés. Aucune modification demandée.\"}");
    if ( requete('matieres',"UPDATE matieres SET dureecolles = $dureecolles, heurescolles = $heurescolles WHERE id = $mid",$mysqli) )
      exit($_SESSION['message'] = "{\"etat\":\"ok\",\"message\":\"Les paramètres de calcul automatique des durées de colle en <em>${r['nom']}</em> ont été modifiés et seront appliqués pour les prochaines déclarations.\",\"reload\":\"2\"}");
    exit("{\"etat\":\"nok\",\"message\":\"Les paramètres de calcul automatique des durées de colle en <em>${r['nom']}</em> n'ont pas été modifiés. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');
  }
  
  // Gestion de la protection du cahier de texte, cdt.php
  if ( ctype_digit($p = $_REQUEST['cdt_protection'] ?? '') )  {
    if ( $p == $r['cdt_protection'] )
      exit("{\"etat\":\"nok\",\"message\":\"Le réglage d'accès au cahier de texte en <em>${r['nom']}</em> n'a pas été modifié. Aucune modification demandée.\"}");
    if ( !requete('matieres',"UPDATE matieres SET cdt_protection = $p WHERE id = $mid",$mysqli) )
      exit("{\"etat\":\"nok\",\"message\":\"Le réglage d'accès au cahier de texte en <em>${r['nom']}</em> n'a pas été modifié. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');
    $mysqli->query("UPDATE utilisateurs SET menuelements='' WHERE FIND_IN_SET($mid,menumatieres)");
    exit($_SESSION['message'] = "{\"etat\":\"ok\",\"message\":\"Le réglage d'accès au cahier de texte en <em>${r['nom']}</em> a été modifié.\",\"reload\":\"2\"}");
  }

  // Gestion de la protection des programmes de colles, progcolles.php
  if ( ctype_digit($p = $_REQUEST['progcolles_protection'] ?? '') )  {
    if ( $p == $r['progcolles_protection'] )
      exit("{\"etat\":\"nok\",\"message\":\"Le réglage d'accès aux programmes de colles en <em>${r['nom']}</em> n'a pas été modifié. Aucune modification demandée.\"}");
    if ( !requete('matieres',"UPDATE matieres SET progcolles_protection = $p WHERE id = $mid",$mysqli) )
      exit("{\"etat\":\"nok\",\"message\":\"Le réglage d'accès aux programmes de colles en <em>${r['nom']}</em> n'a pas été modifié. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');
    // Modification des contenus récents
    requete('recents',"UPDATE recents SET protection = $p WHERE type = 2 AND matiere = $mid".( ( $r['progcolles_protection'] < 32 ) ? ' AND protection < 32' : ''),$mysqli);
    rss($mysqli, $mid, $p, $r['progcolles_protection']);
    $mysqli->query("UPDATE utilisateurs SET menuelements='' WHERE FIND_IN_SET($mid,menumatieres)");
    exit($_SESSION['message'] = "{\"etat\":\"ok\",\"message\":\"Le réglage d'accès aux programmes de colles en <em>${r['nom']}</em> a été modifié.\",\"reload\":\"2\"}");
  }

}

////////////////////////////////////////////////////
// Modification de notes saisies par les colleurs //
////////////////////////////////////////////////////
elseif ( ( $action == 'notescollesgestion' ) && ctype_digit($id = $_REQUEST['id'] ?? '') )  {

  // Vérification que l'identifiant est valide
  $resultat = $mysqli->query("SELECT jour, rattrapage, DATE_FORMAT(jour,'%w%Y%m%e') AS date, duree, releve>0 AS releve, description FROM heurescolles WHERE id = $id AND matiere = $mid");
  if ( !$resultat->num_rows )
    exit('{"etat":"nok","message":"Identifiant non valide"}');
  $r = $resultat->fetch_assoc();
  $resultat->free();

  // Modification d'un champ unique : durée de colle
  if ( ( 'duree' == ( $_REQUEST['champ'] ?? '' ) ) )  {
    if ( ( ( $duree = call_user_func_array(function ($h,$m) { return intval($h)*60+intval($m); }, array_pad(explode('h',$_REQUEST['val'] ?? ''),2,0)) ) <= 0 ) || ( $duree == $r['duree'] ) ) 
      exit('{"etat":"nok","message":"La durée de la colle du <em>'.format_date($r['date']).'</em> n\'a pas été modifiée, aucune modification demandée."}');
    if ( $r['releve'] > 0 )
      exit('{"etat":"nok","message":"La durée de la colle du <em>'.format_date($r['date']).'</em> n\'a pas été modifiée : la relève en a déjà été réalisée."}');
    if ( requete('heurescolles',"UPDATE heurescolles SET duree = '$duree' WHERE id = $id",$mysqli) )
      exit('{"etat":"ok","message":"La durée de la colle du <em>'.format_date($r['date']).'</em> a été modifiée."}');
    exit('{"etat":"nok","message":"La durée de la colle du <em>'.format_date($r['date']).'</em> n\'a pas été modifiée. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');
  }
  
  // Suppression
  if ( isset($_REQUEST['supprime']) )  {
    if ( $r['releve'] > 0 )
      exit('{"etat":"nok","message":"La colle n\'a pas été supprimée car elle a déjà été relevée."}');
    // Heures en séances de TD (pas de notes associées)
    if ( $r['description'] )
      exit( requete('heurescolles',"DELETE FROM heurescolles WHERE id = $id",$mysqli)
          ? '{"etat":"ok","message":"La séance du <em>'.format_date($r['date']).'</em> a été supprimée."}'
          : '{"etat":"nok","message":"La séance du <em>'.format_date($r['date']).'</em> n\'a pas été supprimée. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');
    // Colle avec notes/commentaires
    $resultat = $mysqli->query("SELECT * FROM notescolles WHERE heure = $id");
    $n = $resultat->num_rows;
    $resultat->free();
    exit( requete('notescolles',"DELETE FROM notescolles WHERE heure = $id",$mysqli)
       && requete('heurescolles',"DELETE FROM heurescolles WHERE id = $id",$mysqli)
          ? "{\"etat\":\"ok\",\"message\":\"Les $n notes de colles du <em>".format_date($r['date']).'</em> ont été supprimées."}'
          : "{\"etat\":\"nok\",\"message\":\"Les $n notes de colles du <em>".format_date($r['date']).'</em> n\'ont pas été supprimées. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');
  }

  // Modification
  if ( isset($_REQUEST['jour']) )  {
    $requete = array();
    
    // Validation de la durée (séance non relevée uniquement)
    if ( !$r['releve'] && ( ( $duree = call_user_func_array(function ($h,$m) { return intval($h)*60+intval($m); }, array_pad(explode('h',$_REQUEST['duree'] ?? ''),2,0)) ) > 0 ) && ( $duree != $r['duree'] ) )
      $requete[] = "duree = '$duree'";
      
    // Heures en séances de TD (pas de notes associées)
    if ( $r['description'] )  {
      // Validation du jour
      if ( ( $jour = preg_filter('/(\d{2})\/(\d{2})\/(\d{4})/','$3-$2-$1',$_REQUEST['jour']) ) && ( $jour != $r['jour'] ) )  {
        // Vérification que le jour est bien dans l'année
        $resultat = $mysqli->query("SELECT * FROM semaines WHERE debut <= '$jour' AND debut >= SUBDATE('$jour',7) ORDER BY debut DESC LIMIT 1");
        if ( !$resultat->num_rows )
          exit('{"etat":"nok","message":"Le jour choisi ne se trouve pas dans l\'année scolaire. S\'il s\'agit d\'une erreur, il faut peut-être contacter l\'administrateur."}');
        $resultat->free();
        $requete[] = "jour = '$jour'";
      }
      // Validation de la description
      if ( ( $description = trim(htmlspecialchars($_REQUEST['description'] ?? '')) ) && ( $description != $r['description'] ) )
        $requete[] = 'description = \''.$mysqli->real_escape_string(mb_strtoupper(mb_substr($description,0,1)).mb_substr($description,1)).'\'';
      // Écriture dans la table heurescolles
      if ( $requete )  {
        if ( requete('heurescolles','UPDATE heurescolles SET '.implode(', ',$requete)." WHERE id = $id",$mysqli) ) 
          exit($_SESSION['message'] = '{"etat":"ok","message":"La séance du <em>'.format_date($r['date']).'</em> a été modifiée.","reload":"2"}');
        exit('{"etat":"nok","message":"La séance réalisée par du <em>'.format_date($r['date']).'</em> n\'a pas été modifiée. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');
      }
      else
        exit('{"etat":"nok","message":"La séance du <em>'.format_date($r['date']).'</em> n\'a pas été modifiée. Aucune modification demandée."}');
    }

    // Récupération des notes déjà existantes pour cette heure-là
    $resultat = $mysqli->query("SELECT id, eleve, note FROM notescolles WHERE heure = $id");
    $notesorig = array();
    while ( $s = $resultat->fetch_assoc() )
      $notesorig[$s['eleve']] = $s;
    $resultat->free();
    $resultat = $mysqli->query("SELECT semaine, GROUP_CONCAT(eleve) FROM notescolles WHERE semaine = (SELECT semaine FROM notescolles WHERE heure = $id LIMIT 1) AND matiere = $mid");
    $s = $resultat->fetch_row();
    $resultat->free();
    $semaine = $s[0];
    $notes = array('0','1','2','3','4','5','6','7','8','9','10','11','12','13','14','15','16','17','18','19','20','0,5','1,5','2,5','3,5','4,5','5,5','6,5','7,5','8,5','9,5','10,5','11,5','12,5','13,5','14,5','15,5','16,5','17,5','18,5','19,5','abs','nn');
    $requete_notes = $message = array();
    
    // Validation du jour
    if ( ( $jour = preg_filter('/(\d{2})\/(\d{2})\/(\d{4})/','$3-$2-$1',$_REQUEST['jour']) ) && ( $jour != $r['jour'] ) )  {
      // Vérification que le jour est bien dans la semaine prévue
      $resultat = $mysqli->query("SELECT DATEDIFF('$jour',debut) FROM semaines WHERE id = $semaine OR id = $semaine+1");
      if ( ( ( $j = $resultat->fetch_row()[0] ) >= 0 ) && ( ( ( $resultat->num_rows > 1 ) ? $resultat->fetch_row()[0] : $j-7 ) < 0 ) )
        $requete[] = "jour = '$jour'";
      $resultat->free();
    }
    
    // Récupération de la date de rattrapage si elle est donnée, vérification qu'elle est dans l'année
    if ( ( $rattrapage = preg_filter('/(\d{2})\/(\d{2})\/(\d{4})/','$3-$2-$1',$_REQUEST['rattrapage'] ?? '') ) && ( $rattrapage != $r['rattrapage'] ) )  {
      $resultat = $mysqli->query("SELECT id FROM semaines WHERE debut <= '$rattrapage' AND debut >= SUBDATE('$rattrapage',7) ORDER BY debut DESC LIMIT 1");
      if ( !$resultat->num_rows )
        exit('{"etat":"nok","message":"La date de rattrapage saisie ne se trouve pas dans l\'année scolaire. S\'il s\'agit d\'une erreur, il faut peut-être contacter l\'administrateur."}');
      $resultat->free();
      $requete[] = "rattrapage = '$rattrapage'";
    }
    elseif ( $r['rattrapage'] && !$rattrapage )
      $requete[] = "rattrapage = ''";
  
    // Validation des notes
    foreach ( $notesorig as $eleve => $note )
      if ( isset($_REQUEST["e$eleve"]) && in_array($newnote = $_REQUEST["e$eleve"], $notes, true) && ( $newnote != $note['note'] ) )
        $requete_notes[] = "UPDATE notescolles SET note = '$newnote' WHERE id = ${note['id']}";

    // Exécution
    $ok = true;
    if ( $requete )
      $message[] = ( ( $ok = requete('heurescolles','UPDATE heurescolles SET '.implode(', ',$requete)." WHERE id = $id",$mysqli) )
                     ? 'La colle du <em>'.format_date($r['date']).'</em> a été modifiée.'
                     :'La colle du <em>'.format_date($r['date']).'</em> n\'a pas été modifiée. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'».' );
    if ( $ok && $requete_notes )  {
      $nb = 0;
      foreach ( $requete_notes as $requete )  {
        if ( requete('notescolles',$requete,$mysqli) )
          $nb += 1;
        else  {
          $message[] = 'Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'».';
          $ok = false;
        }
      }
      $message[] = ( ($nb == 1) ? 'Une note a été modifiée.' : "$nb notes ont été modifiées.");
    }
    
    // Reconstruction du message
    if ( !$message )
      exit('{"etat":"nok","message":"La colle du <em>'.format_date($r['date']).'</em> n\'a pas été modifiée. Aucune modification demandée."}');
    if ( $ok )
      exit($_SESSION['message'] = '{"etat":"ok","message":"'.implode('<br>',$message).'","reload":"2"}');
    exit('{"etat":"nok","message":"'.implode('<br>',$message).'"}');
  }
}

// Sans action
exit('{"etat":"nok","message":"Aucune action effectuée"}');
?>
