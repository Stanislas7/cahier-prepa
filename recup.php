<?php
// Sécurité
define('OK',1);
// Configuration
include('config.php');
// Fonctions
include('fonctions.php');

// Vérification du token CSRF
//if ( !isset($_REQUEST['csrf-token']) || isset($_SESSION['csrf-token']) && ( $_REQUEST['csrf-token'] != $_SESSION['csrf-token'] ) )
//  exit('{"etat":"nok","message":"Accès non autorisé"}');

// Récupération de la possibilité de création de compte (formulaire de connexion)
if ( isset($_REQUEST['creationcompte']) )  {
  $mysqli = connectsql();
  $resultat = $mysqli->query('SELECT val FROM prefs WHERE nom = "creation_compte"');
  $r = $resultat->fetch_row();
  $resultat->free();
  $mysqli->close();
  exit("{\"val\":${r[0]}}");
}

// Reconnexion demandée si déconnecté, sauf pour le téléchargement de répertoire
if ( !$autorisation || isset($_REQUEST['auto0']) && ( $_REQUEST['action'] != 'download-rep' ) )
  exit('{"etat":"login"}'); 

// Récupération de l'action
if ( !isset($_REQUEST['action']) || !in_array($action = $_REQUEST['action'],array('docs','download-rep','prefs','compteglobal','transdocs','commentairescolles','listeprofs'),true) )
  exit('{"etat":"nok","message":"Mauvais paramètrage"}');

///////////////////////////////////////////////
// Récupération des répertoires et documents //
///////////////////////////////////////////////
if ( ( $action == 'docs' ) )  {

  $mysqli = connectsql();
  $resultat = $mysqli->query("SELECT m.id AS mid, m.nom AS mnom, r.id AS rid, d.id AS did, CONCAT(d.nom,IF(LENGTH(d.ext),CONCAT(' (',d.ext,')'),'')) AS dnom, 
                                     CONCAT_WS('/', ( SELECT GROUP_CONCAT(reps.nom ORDER BY FIND_IN_SET(reps.id,r.parents) SEPARATOR '/') FROM reps WHERE FIND_IN_SET(reps.id,r.parents) ), r.nom) AS rnom
                                     FROM docs AS d LEFT JOIN reps AS r ON r.id = d.parent LEFT JOIN ( (SELECT id, ordre, nom FROM matieres) UNION (SELECT 0, 0, 'Documents généraux') ) AS m ON m.id = r.matiere 
                                     WHERE FIND_IN_SET(m.id,'${_SESSION['matieres']}') AND ".requete_protection($autorisation,'d.').' ORDER BY m.ordre, rnom, nom_nat');
  if ( $resultat->num_rows )  {
    $mats = '<option value="-1">[Choisissez une matière]</option>';
    $reps = array( -1 =>'<option value="-1">[Choisissez une matière]</option>');
    $docs = array( -1 => '<option value="0">[Choisissez une matière]</option>', 0 => '<option value="0">[Choisissez un répertoire]</option>' );
    $mid = $rid = -1;
    while ( $r = $resultat->fetch_assoc() )  {
      if ( $r['mid'] != $mid )  { 
        $mid = $r['mid'];
        $mats .= "<option value=\"$mid\">${r['mnom']}</option>";
        $reps[$mid] = '<option value="0">[Choisissez un répertoire]</option>';
      }
      if ( $r['rid'] != $rid )  { 
        $rid = $r['rid'];
        $reps[$mid] .= "<option value=\"$rid\">${r['rnom']}</option>";
        $docs[$rid] = '<option value="0">[Choisissez un document]</option>';
      }
      $docs[$rid] .= "<option value=\"${r['did']}\">${r['dnom']}</option>";
    }
    $resultat->free();
  }
  exit(json_encode(array('recupok'=>1,'mats'=>$mats ?? array(),'reps'=>$reps ?? array(),'docs'=>$docs ?? array())));

}

//////////////////////////////////////////////
// Récupération des documents à télécharger //
//////////////////////////////////////////////
if ( ( $action == 'download-rep' ) && isset($_REQUEST['id']) && ctype_digit($id = $_REQUEST['id']) )  {

  // Pas de téléchargement en mode lecture
  if ( $_SESSION['mode_lecture'] )
    exit('{"etat":"nok","message":"Le téléchargement est désactivé en mode lecture."}');
  // Vérification que l'identifiant est valide
  $mysqli = connectsql();
  $resultat = $mysqli->query("SELECT nom, matiere, protection, zip FROM reps WHERE id = $id");
  if ( !$resultat->num_rows )
    exit('{"etat":"nok","message":"Identifiant de répertoire non valide"}');
  list($nom, $m, $p, $z) = $resultat->fetch_row();
  $resultat->free();
  
  // Vérification de la protection
  // On ne peut pas utiliser acces() car en POST
  // Accès autorisé si :
  // * prof éditeur
  // * prof hors matière si protection < 17 et zipable
  // * non prof mais connecté, matière ok , protection ok, zipable
  // * non connecté, pas de protection, zipable
  // Zip : 0 si non zipable, 1 si zipable par les connectés, 2 si par tous. 
  // Professeur éditeur : pas de vérif du caractère zipable
  $matieres = explode(',',$_SESSION['matieres']);
  if ( ( $autorisation == 5 ) && in_array($m,$matieres) )  {
    $requete_protection = ( isset($_REQUEST['docscaches']) ) ? 'AND protection < 32' : '';
    $requete_zip = '';
  }
  // Non connecté, pas de protection, zipable
  elseif ( !$autorisation && !$p && ( $z == 2 ) )  {
    $requete_protection = 'AND protection = 0';
    $requete_zip = 'AND zip = 2';
  }
  // Si professeur ayant accès à une matière en tant que colleur, modification
  // globale de $autorisation et accès possible dans l'un ou l'autre des deux cas
  elseif ( ( $autorisation == 5 ) && $z && strpos($_SESSION['matieres'],'c') && in_array("c$m",$matieres) && ( !$p || (32-$p) & 20 ) )  {
    //$autorisation = 3;
    $requete_protection = 'AND (protection = 0 OR ( (32-protection) & 20 ) )';
    $requete_zip = 'AND zip';
  }
  // Si prof hors matière : protection < 17 et zipable
  elseif ( ( $autorisation == 5 ) && ( $p < 17 ) && $z )  {
    $requete_protection = 'AND protection < 17';
    $requete_zip = 'AND zip';
  }
  // Connecté associé à la matière, protection ok, zipable
  elseif ( $autorisation && $z && in_array($m,$matieres) && ( !$p || (32-$p) & 2**($autorisation-1) ) )  {
    $requete_protection = 'AND (protection = 0 OR ( (32-protection) & '.(2**($autorisation-1)).' ) )';
    $requete_zip = 'AND zip';
  }
  else
    exit('{"etat":"nok","message":"Identifiant de répertoire non valide"}');

  // Identifiants des éléments à télécharger
  $rids = implode(',',array_filter($_REQUEST['reps'] ?? array(),'ctype_digit'));
  $dids = implode(',',array_filter($_REQUEST['docs'] ?? array(),'ctype_digit'));
  $requete = ( $dids ) ? "parent = $id AND FIND_IN_SET(id,'$dids')" : '';
  if ( $rids )  {
    // Vérification des répertoires : on ne garde que les enfants directs
    $resultat = $mysqli->query("SELECT GROUP_CONCAT(id) FROM reps WHERE parent = $id AND FIND_IN_SET(id,'$rids') $requete_protection $requete_zip");
    $rids = $resultat->fetch_row()[0];
    $resultat->free();
    // Récupération des sous-répertoires enfants du niveau supérieur
    if ( $rids )  {
      $resultat = $mysqli->query('SELECT GROUP_CONCAT(id) FROM reps WHERE ('. implode(' OR ', array_map(function($r) { return "FIND_IN_SET($r,parents)"; }, explode(',',$rids) ) ) .") $requete_protection $requete_zip" );
      if ( $rids2 = $resultat->fetch_row()[0] )
        $rids .= ",$rids2";
      $resultat->free();
    }
    $requete .= ( $dids ) ? " OR FIND_IN_SET(parent,'$rids')" : "FIND_IN_SET(parent,'$rids')";
  }
  
  // Récupération des documents concernés et génération d'un code de vérification,
  // qui évite la gestion de l'accès dans download.php
  // On prend la protection et la dispo du document dans le code de vérif, et 
  // l'id du parent et sa valeur zip : si ça change, le code est caduque.
  if ( !$requete )
    exit('{"etat":"nok","message":"Aucun document à télécharger"}');
  if ( $requete_protection ) 
    $requete_protection .= ' AND dispo < NOW()';
  $resultat = $mysqli->query("SELECT id, lien, taille, protection, dispo, rid, zip
                              FROM docs JOIN ( SELECT id AS rid, zip FROM reps ) AS r ON rid = parent WHERE ( $requete ) $requete_protection");
  $total = 0;
  if ( !$resultat->num_rows )
    exit('{"etat":"nok","message":"Aucun document à télécharger"}');
  $dids = array();
  $verifs = array();
  $taille = 0;
  while ( $r = $resultat->fetch_assoc() )  {
    $dids[] = $r['id'];
    $verifs[] = sha1("r${r['rid']}-d${r['id']}-${r['lien']}-${r['protection']}-${r['dispo']}-${r['zip']}-$mdp");
    $taille +=  eval('return ('.str_replace(array('&nbsp;ko','&nbsp;Mo'),array('+0.5)*1024','+0.5)*1048576'), $r['taille'] ).';');
  }
  $resultat->free();
  
  // Envoi de la réponse
  exit(json_encode(array('etat' => 'recupok', 'nom' => $nom, 'dids' => implode(',',$dids), 'verifs' => implode(',',$verifs), 'taille' => $taille ) ) );
}

///////////////////////////////////////////////
// Récupération des données d'un utilisateur //
///////////////////////////////////////////////
elseif ( ( $action == 'prefs' ) && $_SESSION['admin'] && connexionlight() && isset($_REQUEST['id']) && ctype_digit($id = $_REQUEST['id']) )  {

  // Vérification que l'identifiant est valide
  $mysqli = connectsql();
  $resultat = $mysqli->query("SELECT nom, prenom, login, matieres, mail as mail1, (LENGTH(mdp)=40) AS valide, (LEFT(mdp,1)='*') AS demande, (LENGTH(mdp)=1) AS invitation, autorisation%10 AS autorisation, autorisation>10 AS admin, mailexp, mailcopie FROM utilisateurs WHERE id = $id");
  if ( $resultat->num_rows )  {
    $r = $resultat->fetch_assoc();
    $resultat->free();
    // Problème d'encodage des entiers, renvoyés en tant que chaîne.
    // Semble dépendre du driver MySQL, ne prenons pas de risque
    $r['valide'] = intval($r['valide']);
    $r['demande'] = intval($r['demande']);
    $r['invitation'] = intval($r['invitation']);
    $r['autorisation'] = intval($r['autorisation']);
    $r['admin'] = intval($r['admin']);
    $r['mailcopie'] = intval($r['mailcopie']);
    // Récupération des autorisations d'envoi
    $resultat = $mysqli->query('SELECT val FROM prefs WHERE nom = "autorisation_mails"');
    $r['mailenvoi'] = ( $r['autorisation'] > 1 ) ? intval(( $resultat->fetch_row()[0] >> 4*($r['autorisation']-2) & 15 ) > 0) : 0;
    $resultat->free();
    $mysqli->close();
    $r['mail2'] = '';
    $r['etat'] = 'recupok';
    exit(json_encode($r));
  }
  exit('{"etat":"nok","message":"Identifiant non valide"}');

}

///////////////////////////////////////////////
// Récupération des données d'un utilisateur //
///////////////////////////////////////////////
elseif ( ( $action == 'compteglobal' ) && ( $autorisation > 1 ) && $interfaceglobale )  {

  // $_SESSION['compteglobal'] contient l'identifiant du compte à utiliser
  // La deuxième partie de la requête sert de vérification : 
  //  * compte contenant une connexion vers cet utilisateur de ce Cahier
  //  * compte contenant au moins une autre connexion 
  $mysqli = connectsql(false,$interfaceglobale);
  $resultat = $mysqli->query("SELECT connexions FROM comptes 
                              WHERE id = ${_SESSION['compteglobal']} 
                                AND FIND_IN_SET((SELECT id FROM cahiers WHERE rep = TRIM(BOTH '/' FROM '${GLOBALS['chemin']}'))*1000+${_SESSION['id']}, connexions)
                                AND LOCATE(',',connexions)");
  if ( $resultat->num_rows )  {
    $cahiers = implode(',', array_filter(array_map( function($v){return ($v>0)?intdiv($v,1000):false;}, explode(',',$resultat->fetch_row()[0]) )) );
    $resultat->free();
    $resultat = $mysqli->query("SELECT rep, CONCAT(classe,' - ',nom,' (',ville,') ') AS classe
                                FROM cahiers LEFT JOIN lycees ON lycee = lycees.id
                                WHERE FIND_IN_SET(cahiers.id,'$cahiers') ORDER BY FIND_IN_SET(cahiers.id,'$cahiers')");
    $reps = array();
    while ( $r = $resultat->fetch_assoc() ) 
      if ( "/${r['rep']}/" != $chemin )
        $reps[$r['rep']] = $r['classe'];
    $resultat->free();
    exit(json_encode(array('etat'=>'recupok','cahiers'=>$reps)));
  }
  exit('{"etat":"nok","message":"Identifiant non valide"}');
}

////////////////////////////////////////////////////////////
// Récupération de la liste des documents d'un transfert  //
////////////////////////////////////////////////////////////
elseif ( ( $action == 'transdocs' ) && isset($_REQUEST['id']) && ctype_digit($id = $_REQUEST['id']) )  {

  // Vérification de l'autorisation
  $mysqli = connectsql();
  $resultat = $mysqli->query("SELECT matiere, type & 1 as envoi, type & 2 as aut_colleurs, lien FROM transferts WHERE id = $id" . ( $autorisation < 5 ? " AND ( type>>($autorisation-2) & 1 )" : '' ));
  if ( !$resultat->num_rows )
    exit('{"etat":"nok","message":"Identifiant non valide"}');
  $r = $resultat->fetch_row();
  // Vérification de la matière
  if ( !in_array($mid = $r[0],explode(',',$_SESSION['matieres'])) )  {
    // Cas des profs-colleurs
    if ( ( $autorisation != 5 ) || !in_array("c$mid",explode(',',$_SESSION['matieres'])) || !$r[2] )
      exit('{"etat":"nok","message":"Identifiant non valide"}');
    $autorisation = 3;
  }
  $envoi = $r[1]; // 0 si depuis les élèves, 1 si vers les élèves
  $lien = $r[3];
  
  // Gestion de l'ordre d'affichage
  switch ( $_REQUEST['ordre'] ?? '' )  {
    case 'alphadesc':          $ordre = 'ORDER BY nomcomplet DESC, numero';             break;
    case 'chronoasc':          $ordre = 'ORDER BY upload ASC, nomcomplet ASC, numero';  break;
    case 'chronodesc':         $ordre = 'ORDER BY upload DESC, nomcomplet ASC, numero'; break;
    case 'alphaasc': default:  $ordre = 'ORDER BY nomcomplet ASC, numero';
  }
  // Récupération
  $restriction = ( !$envoi || ( $autorisation == 5 ) ) ? '1' : "( utilisateur = ${_SESSION['id']} )";
  $resultat = $mysqli->query("SELECT doc1.id, doc1.eleve, CONCAT(nom,' ',prenom) AS nomcomplet, date, taille, ext, numero, n, $restriction AS ok
                              FROM ( SELECT id, eleve, utilisateur, numero, upload, DATE_FORMAT(upload,'%e/%m/%Y %kh%i') AS date, ext, taille
                                     FROM transdocs WHERE transfert = $id ) AS doc1
                              LEFT JOIN utilisateurs ON doc1.eleve = utilisateurs.id
                              LEFT JOIN ( SELECT COUNT(*) AS n, eleve FROM transdocs WHERE transfert = $id GROUP BY eleve ) AS doc2
                                        ON doc1.eleve = doc2.eleve
                              $ordre");
  // Génération de la réponse
  $lignes = array();
  if ( $resultat->num_rows )  {
    while ( $r = $resultat->fetch_row() )  {
      // Problème d'encodage des entiers, renvoyés en tant que chaîne.
      // Semble dépendre du driver MySQL, ne prenons pas de risque
      // Pas d'affichage du numéro s'il n'y a qu'un document pour un élève
      // Pas d'affichage détaillé si l'utilisateur est colleur ou lycée
      if ( $r[7] > 1 )
        $r[2] .= "&nbsp;(${r[6]}/${r[7]})";
      $r[0] = intval($r[0]);
      $r[1] = intval($r[1]);
      $r[6] = sha1("d${r[0]}-${r[1]}-$lien-$mdp");
      // Enregistrement
      $lignes[] = ( $r[8] ) ? array_slice($r,0,7) : array_slice($r,0,3);
    }
    $resultat->free();
  }
  exit(json_encode(array('etat' => 'recupok', 'lignes' => $lignes)));
}

/////////////////////////////////////////////
// Récupération des commentaires de colles //
/////////////////////////////////////////////
elseif ( ( $action == 'commentairescolles' ) && isset($_REQUEST['id']) && ctype_digit($id = $_REQUEST['id']) )  {

  // Récupération des données de l'heure demandée
  $mysqli = connectsql();
  $resultat = $mysqli->query("SELECT eleve, note, commentaire, matiere, colleur FROM notescolles WHERE heure = $id");
  $mysqli->close();
  
  if ( $resultat->num_rows )  {
    $matieres = explode(',',$_SESSION['matieres']);
    $nok = true;
    $notes = $comms = array();
    while ( $r = $resultat->fetch_assoc() )  {
      
      // Vérification des droits d'accès : tout colleur pour le prof de la
      // matière, seulement soi-même pour un colleur
      // Vérification au premier passage uniquement
      if ( $nok )  {
        $nok = !( ( $autorisation == 5 ) && in_array($r['matiere'],$matieres) || ( ( $autorisation == 3 ) && in_array($r['matiere'],$matieres) || ( $autorisation == 5 ) && in_array("c${r['matiere']}",$matieres) ) && ( $r['colleur'] == $_SESSION['id'] ) );
        if ( $nok )  {
          $resultat->free();
          exit('{"etat":"nok","message":"Identifiant non valide"}');
        }
      }

      // Encodage du commentaire en base 64 pour éviter les problèmes de parsing en js.
      $eleve = intval($r['eleve']);
      $notes[$eleve] = $r['note'];
      if ( $r['commentaire'] )
        $comms[$eleve] = base64_encode(rawurlencode($r['commentaire']));
    }
    $resultat->free();
    exit(json_encode(array('etat' => 'recupok', 'notes' => $notes, 'comms' => $comms)));
  }
  exit('{"etat":"nok","message":"Identifiant non valide"}');
}

///////////////////////////////////////////////////////////////////////////////////
// Récupération de la liste des profs que l'on peut ajouter en tant que colleurs //
///////////////////////////////////////////////////////////////////////////////////
elseif ( ( $action == 'listeprofs' ) && $_SESSION['admin'] && connexionlight() )  {
  
  $mysqli = connectsql();
  $resultat = $mysqli->query('SELECT id, IF(LENGTH(nom),CONCAT(nom,\' \',prenom),login) AS nomcomplet, matieres FROM utilisateurs WHERE autorisation%10 = 5 AND NOT LOCATE(\'c\',matieres) ORDER BY nomcomplet');
  $mysqli->close();
  if ( $resultat->num_rows )  {
    $noms = $matieres = array();
    while ( $r = $resultat->fetch_row() )  {
      $ids[] = $r[0];
      $noms[] = $r[1];
      $matieres[] = $r[2];
    }
    $resultat->free();
    exit(json_encode(array('etat' => 'recupok', 'ids' => $ids, 'noms' => $noms, 'matieres' => $matieres)));
  }
  else 
    exit('{"etat":"recupok"}');
}

// Réponse par défaut
exit( $message ?: '{"etat":"nok","message":"Accès interdit"}' );
?>
