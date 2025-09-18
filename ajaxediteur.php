<?php
// Sécurité : script obligatoirement inclus par ajax.php
if ( !defined('OK') )  exit();

// Script d'exécution des commandes ajax pour l'édition
// Nécessite d'être connecté en connexion normale (vérification en fonction de l'élément à éditer)
if ( !$autorisation || !connexionlight() )
  exit( '{"etat":"nok","message":"Aucune action effectuée"}' );
$mysqli = connectsql(true);
// Spécifications pour les manipulations de caractères sur 2 octets (accents)
mb_internal_encoding('UTF-8');

///////////////////////////////////
// Modification des informations //
///////////////////////////////////
if ( $action == 'infos' && ctype_digit($id = $_REQUEST['id'] ?? '') )  {
  
  // Vérification de l'identifiant et récupération des données
  $resultat = $mysqli->query("SELECT i.ordre, i.titre, cache, texte, page, cle, i.protection, i.edition, IF(dispo,DATE_FORMAT(dispo,'%Y-%m-%d %H:%i'),0) AS dispo,
                              ( SELECT COUNT(*) FROM infos WHERE page = i.page ) AS max,
                              p.matiere, p.protection AS protectionpage, p.edition AS editionpage, FIND_IN_SET(p.matiere,'${_SESSION['matieres']}') AS matiereassociee
                              FROM infos AS i LEFT JOIN pages AS p ON i.page = p.id WHERE i.id = $id");
  if ( !$resultat->num_rows )
    exit('{"etat":"nok","message":"Identifiant d\'information non valide"}');
  $r = $resultat->fetch_assoc();
  $resultat->free();
  // Vérification de l'accès
  // * les professeurs de la matière peuvent tout faire
  // * les éditeurs de l'information peuvent éditer titre et texte
  // * les éditeurs de la page seulement ne peuvent rien faire
  // * les éditeurs de l'info et de la page peuvent tout faire
  if ( ( $autorisation == 5 ) && $r['matiereassociee'] )
    $editionglobale = true;
  // Pour les utilisateurs autorisés à l'édition de l'information, professeurs hors de la matière
  // ou autres utilisateurs associés à la matière, seuls titre et texte sont modifiables
  elseif ( $r['edition'] && ( ($r['edition']-1)>>($autorisation-1) & 1 ) && ( ( $autorisation == 5 ) || $r['matiereassociee'] ) )
    // Ils peuvent tout faire si droit d'édition sur la page
    $editionglobale = $r['editionpage'] && ( ($r['editionpage']-1)>>($autorisation-1) & 1 );
  // Cas des professeurs-colleurs
  elseif ( $r['edition'] && ( $autorisation == 5 ) && strpos($_SESSION['matieres'],'c') && in_array("c${r['matiere']}",explode(',',$_SESSION['matieres'])) && ( ($r['edition']-1) & 4 ) )
    $editionglobale = $r['editionpage'] && ( ($r['editionpage']-1) & 4 );
  else
    exit('{"etat":"nok","message":"Identifiant d\'information non valide"}');

  // Traitement d'une modification sans nécessité d'édition de la page : titre, texte
  if ( isset($_REQUEST['champ']) )  {
    $valeur = trim($mysqli->real_escape_string($_REQUEST['val'] ?? ''));
    $champ = $_REQUEST['champ'];
    if ( isset($r[$champ = $_REQUEST['champ']]) && ( $r[$champ] == $valeur ) )
      exit('{"etat":"nok","message":"L\'information n\'a pas été modifiée."}');
    if ( $champ == 'titre' )
      exit( requete('infos',"UPDATE infos SET titre = '$valeur' WHERE id = $id",$mysqli) && recent($mysqli,1,$id,array('titre'=>$valeur))
         ? '{"etat":"ok","message":"Le titre de l\'information a été modifié."}'
         : '{"etat":"nok","message":"L\'information n\'a pas été modifiée. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');
    if ( $champ == 'texte' )  {
      if ( !$valeur )
        exit('{"etat":"nok","message":"L\'information n\'a pas été modifiée. Le texte doit être non vide."}');
      exit( requete('infos',"UPDATE infos SET texte = '$valeur' WHERE id = $id",$mysqli) && recent($mysqli,1,$id,array('texte'=>$valeur),isset($_REQUEST['publi']))
         ? '{"etat":"ok","message":"Le texte de l\'information a été modifié."}'
         : '{"etat":"nok","message":"L\'information n\'a pas été modifiée. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');
    }
  }

  // Autres modifications, nécessitant le droit d'éditer la page
  if ( $editionglobale )  {
  
    // Déplacement vers le haut
    if ( isset($_REQUEST['monte']) && ( $r['ordre'] > 1 ) )
      exit( requete('infos',"UPDATE infos SET ordre = (2*${r['ordre']}-1-ordre) WHERE ( ordre = ${r['ordre']} OR ordre = (${r['ordre']}-1) ) AND page = ${r['page']}",$mysqli)
        ? '{"etat":"ok","message":"L\'information a été déplacée."}'
        : '{"etat":"nok","message":"L\'information n\'a pas été déplacée. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');

    // Déplacement vers le bas
    if ( isset($_REQUEST['descend']) && ( $r['ordre'] < $r['max'] ) )
      exit( requete('infos',"UPDATE infos SET ordre = (2*${r['ordre']}+1-ordre) WHERE ( ordre = ${r['ordre']} OR ordre = (${r['ordre']}+1) ) AND page = ${r['page']}",$mysqli)
        ? '{"etat":"ok","message":"L\'information a été déplacée."}'
        : '{"etat":"nok","message":"L\'information n\'a pas été déplacée. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');

    // Droits d'accès et d'édition
    if ( ctype_digit($protection = $_REQUEST['protection'] ?? '') && ctype_digit($edition = $_REQUEST['edition'] ?? '') )  {
      // La protection de l'information et la protection de la page sont
      // indépendantes l'une de l'autre. Un utilisateur peut voir l'info sur
      // les événements récents sans la voir sur la page. 
      // Sans matière, protection non nulle entre 1 et 15, ou 32 
      if ( !$r['matiere'] && $protection ) 
        $protection = ( $protection & 15 ) ?: 32;
      // Validation de l'édition. Les deux protections (page et info) doivent
      // obligatoirement inclure l'édition.
      $edition = ( $edition ) ? ($edition = ($edition-1) & (32-($protection?:1)) & (32-($r['protectionpage']?:1)) & 30) + ($edition>0) : 0;
      // Pas de modification
      if ( ( $protection == $r['protection'] ) && ( $edition == $r['edition'] ) )
        exit('{"etat":"nok","message":"Le réglage d\'accès à l\'information n\'a pas été modifié."}');
      // Suppression de l'affichage différé si invisible
      if ( ( $protection != $r['protection'] ) )  {
        $modif = ( $protection == 32 ) ? 'cache = 1, protection = 32, dispo = 0' : "cache = 0, protection = $protection";
        if ( !requete('infos',"UPDATE infos SET $modif WHERE id = $id",$mysqli) || !recent($mysqli,1,$id,array('protection'=>$protection)) )
          exit('{"etat":"nok","message":"Le réglage d\'accès à l\'information n\'a pas été modifié. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');
      }
      if ( ( $edition != $r['edition'] ) )  {
        if ( !requete('infos',"UPDATE infos SET edition = $edition WHERE id = $id",$mysqli) )
          exit('{"etat":"nok","message":"Le réglage d\'accès à l\'information n\'a pas été modifié. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');
      }
      exit($_SESSION['message'] = '{"etat":"ok","message":"Le réglage d\'accès à l\'information a été modifiée.","reload":"1"}');
    }

    // Modification de la date de disponibilité
    if ( isset($_REQUEST['dispo']) )  {
      if ( $dispo = preg_filter('/(\d{2})\/(\d{2})\/(\d{4}) (\d{1,2})h(\d{2})/','$3-$2-$1 $4:$5',$_REQUEST['dispo']) )  {
        if ( $r['protection'] + $r['protectionpage'] == 64 )
          exit('{"etat":"nok","message":"Il est impossible de régler un affichage différé pour une information invisible sur une page invisible. Vous devez d\'abord modifier sa visibilité en cliquant sur le réglage de la protection <span class=\"icon-lock\"></span>."}');
        if ( strlen($dispo) == 15 )
          $dispo = substr($dispo,0,-4).'0'.substr($dispo,-4);
        if ( $r['dispo'] == $dispo )
          exit('{"etat":"nok","message":"La date de disponibilité de l\'information n\'a pas été modifiée."}');
        // Modification uniquement si dispo est dans le futur
        if ( $dispo < date('Y-m-d H:i') ) 
          exit('{"etat":"nok","message":"La date de disponibilité de l\'information n\'a pas été modifiée car la valeur donnée est déjà passée."}');
        // Visibilité automatiquement rendue
        $modif = "dispo = '$dispo'";
        $modifrecent = array('publi'=>$dispo);
        if ( $r['cache'] )  {
          $modif .= ", cache = 0, protection = ${r['protectionpage']}";
          $modifrecent['protection'] = $r['protectionpage'];
        }
        if ( requete('infos',"UPDATE infos SET $modif WHERE id = $id",$mysqli) && recent($mysqli,1,$id,$modifrecent) )
          exit($_SESSION['message'] = '{"etat":"ok","message":"La date de disponibilité de l\'information a été modifiée '.$_REQUEST['dispo'].'.","reload":"1"}');
        exit('{"etat":"nok","message":"La date de disponibilité de l\'information n\'a pas été modifiée. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');
      }
      // Pas de dispo donnée : suppression de l'affichage différé si existant
      // et dans le futur -> affichage immédiat
      elseif ( $r['dispo'] > date('Y-m-d H:i') )  {
        if ( requete('infos',"UPDATE infos SET dispo = 0 WHERE id = $id",$mysqli) && recent($mysqli,1,$id,array('publi'=>0)) )
          exit($_SESSION['message'] = '{"etat":"ok","message":"L\'information apparaît désormais pour les autres utilisateurs.","reload":"1"}');
        exit('{"etat":"nok","message":"La date de disponibilité de l\'information n\'a pas été supprimée. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');
      }
    }

    // Apparition, avec la protection de base de la page
    if ( isset($_REQUEST['montre']) )  {
      if ( $r['protectionpage'] == 32 )
        exit('{"etat":"nok","message":"Il est impossible de «&nbsp;montrer&nbsp;» une information sur une page invisible. Mais vous pouvez modifier sa visibilité en cliquant sur le réglage de la protection <span class=\"icon-lock\"></span>."}');
      exit( requete('infos',"UPDATE infos SET cache = 0, protection = ${r['protectionpage']}, edition = 0 WHERE id = $id",$mysqli) && recent($mysqli,1,$id,array('protection'=>$r['protectionpage']))
          ? '{"etat":"ok","message":"L\'information apparaît désormais pour les autres utilisateurs."}'
          : '{"etat":"nok","message":"L\'information n\'a pas été diffusée. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');
    }

    // Disparition
    // Supprime l'éventuel affichage différé
    if ( isset($_REQUEST['cache']) )  {
      if ( requete('infos',"UPDATE infos SET cache = 1, protection = 32, edition = ${r['editionpage']}, dispo = 0 WHERE id = $id",$mysqli) && recent($mysqli,1,$id,array('protection'=>32)) )  {
        // Rechargement si les droits d'accès sont différents de ceux de la page (pour enlever les icônes)
        if ( ( $r['protection'] != $r['protectionpage'] ) || ( $r['edition'] != $r['editionpage'] ) )
          exit($_SESSION['message'] = '{"etat":"ok","message":"L\'information n\'apparaît plus désormais pour les autres utilisateurs mais est toujours disponible ici pour modification ou diffusion.","reload":"1"}');
        exit('{"etat":"ok","message":"L\'information n\'apparaît plus désormais pour les autres utilisateurs mais est toujours disponible ici pour modification ou diffusion."}');
      }
      exit('{"etat":"nok","message":"L\'information n\'a pas été cachée. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');
    }

    // Suppression
    if ( isset($_REQUEST['supprime']) )  {
      if ( !requete('infos',"DELETE FROM infos WHERE id = $id",$mysqli)
        || !requete('infos',"UPDATE infos SET ordre = (ordre-1) WHERE ordre > ${r['ordre']} AND page = ${r['page']}",$mysqli) )
      exit('{"etat":"nok","message":"L\'information n\'a pas été supprimée. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');
      recent($mysqli,1,$id);
      # Rechargement si on supprime la dernière information
      $resultat = $mysqli->query("SELECT id FROM infos WHERE page = ${r['page']} LIMIT 1");
      if ( $resultat->num_rows )  {
        $resultat->free();
        exit('{"etat":"ok","message":"L\'information a été supprimée."}');
      }
      exit($_SESSION['message'] = '{"etat":"ok","message":"L\'information a été supprimée.","reload":"1"}');
    }
  }
}

/////////////////////////////////////////
// Suppression multiple d'informations //
/////////////////////////////////////////
if ( $action == 'supprime-infos' && ctype_digit($pid = $_REQUEST['id'] ?? '') && ( $ids = implode(',',array_filter($_REQUEST['infos'] ?? array(),'ctype_digit')) ) )  {
  
  // Vérification de l'accès : récupération de la page
  // Seuls les professeurs de la matière ou les éditeurs de la page peuvent agir
  $resultat = $mysqli->query("SELECT matiere, edition, FIND_IN_SET(matiere,'${_SESSION['matieres']}') AS matiereassociee FROM pages WHERE id = $pid");
  if ( !$resultat->num_rows )
    exit('{"etat":"nok","message":"Identifiant de page non valide"}');
  $r = $resultat->fetch_assoc();
  $resultat->free();
  if ( !( ( $autorisation == 5 ) && $r['matiereassociee'] ) )  {
    // Utilisateur classique, autorisé par 'edition', et associé à la matière
    if ( !( $r['edition'] && ( ($r['edition']-1)>>($autorisation-1) & 1 ) && $r['matiereassociee'] ) )  { 
      // Cas des professeurs-colleurs
      if ( !( $r['edition'] && ( $autorisation == 5 ) && strpos($_SESSION['matieres'],'c') && in_array("c${r['matiere']}",explode(',',$_SESSION['matieres'])) && ( ($r['edition']-1) & 4 ) ) )
        exit('{"etat":"nok","message":"Identifiant de page non valide"}');
    }
  }
  
  // Vérification des identifiants des informations
  $resultat = $mysqli->query("SELECT GROUP_CONCAT(id) FROM infos WHERE page = $pid AND FIND_IN_SET(id,'$ids')");
  $ids = $resultat->fetch_row()[0];
  $resultat->free();
  if ( !$ids )
    exit('{"etat":"nok","message":"Aucune information à supprimer"}');
  $mysqli->query('SET @nouv = 0');
  if ( !requete('infos',"DELETE FROM infos WHERE FIND_IN_SET(id,'$ids')",$mysqli)
    || !requete('infos',"UPDATE infos SET ordre = (@nouv := @nouv + 1) WHERE page = $pid ORDER BY ordre",$mysqli) )
    exit('{"etat":"nok","message":"Les informations n\'ont pas été supprimées. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');
  recents($mysqli,1,explode(',',$ids));
  # Rechargement
  exit($_SESSION['message'] = '{"etat":"ok","message":"Les informations ont été supprimées.","reload":"1"}');
}

/////////////////////////////
// Ajout d'une information //
/////////////////////////////
elseif ( ( $action == 'ajout-info' ) && ctype_digit($page = $_REQUEST['id'] ?? '') && ctype_digit($protection = $_REQUEST['protection'] ?? '') && ctype_digit($edition = $_REQUEST['edition'] ?? '') )  {

  // Vérification de l'identifiant de la page
  $resultat = $mysqli->query("SELECT matiere, FIND_IN_SET(matiere,'${_SESSION['matieres']}') AS matiereassociee, protection, edition FROM pages WHERE id = $page");
  if ( !$resultat->num_rows )
    exit('{"etat":"nok","message":"Identifiant de page non valide"}');
  $r = $resultat->fetch_assoc();
  $resultat->free();
  
  // Vérification de l'accès
  // * les professeurs de la matière
  // * les éditeurs de la page (profs autorisés, profs-colleurs de la matière, autres types de comptes de la matière)
  if ( !(   ( $autorisation == 5 ) && ( $r['matiereassociee'] || ( $r['edition'] > 16 ) || $r['edition'] && strpos($_SESSION['matieres'],'c') && in_array("c${r['matiere']}",explode(',',$_SESSION['matieres'])) && ( ($r['edition']-1)>>2 & 1 ) ) 
         || ( $autorisation < 5 ) && $r['matiereassociee'] && $r['edition'] && ( ($r['edition']-1)>>($autorisation-1) & 1 ) ) )
    exit('{"etat":"nok","message":"Identifiant de page non valide"}');

  // Vérification de l'affichage différé et de la date de disponibilité, seulement si dans le futur et si visible
  if ( !isset($_REQUEST['affdiff']) || ( $protection == 32 ) || is_null( $dispo = preg_filter('/(\d{2})\/(\d{2})\/(\d{4}) (\d{1,2})h(\d{2})/','$3-$2-$1 $4:$5',$_REQUEST['dispo'] ?? '') ) || ( $dispo < date('Y-m-d H:i') ) )
    $dispo = 0;
  // Vérification des données
  $titre = trim($mysqli->real_escape_string($_REQUEST['titre'] ?? ''));
  if ( !( $texte = trim($mysqli->real_escape_string($_REQUEST['texte'] ?? '')) ) )
    exit('{"etat":"nok","message":"L\'information n\'a pas été ajoutée. Le texte doit être non vide."}');
  // La protection de l'information et la protection de la page sont
  // indépendantes l'une de l'autre. Un utilisateur peut voir l'info sur
  // les événements récents sans la voir sur la page. 
  // Sans matière, protection non nulle entre 1 et 15, ou 32 
  if ( !$r['matiere'] && $protection ) 
    $protection = ( $protection & 15 ) ?: 32;
  // Validation de l'édition. Les deux protections (page et info) doivent
  // obligatoirement inclure l'édition.
  $edition = ( $edition ) ? ($edition = ($edition-1) & (32-($protection?:1)) & (32-($r['protection']?:1)) & 30) + ($edition>0) : 0;
  // Écriture
  $requete = ( $protection == 32 ) ? 'cache = 1, protection = 32, edition = 0' : "cache = 0, protection = $protection, edition = $edition";
  if ( requete('infos',"UPDATE infos SET ordre = (ordre+1) WHERE page = $page",$mysqli)
    && requete('infos',"INSERT INTO infos SET ordre = 1, page = $page, texte = '$texte', titre = '$titre', $requete, dispo='$dispo'",$mysqli) 
    && recent($mysqli,1,$mysqli->insert_id,true) )  {
    if ( $protection == 32 )
      $message = 'L\'information a été ajoutée mais reste invisible pour l\'instant.';
    elseif ( $dispo )
      $message = 'L\'information a été ajoutée mais ne sera visible que le '.substr($_REQUEST['dispo'],0,10).' à '.substr($_REQUEST['dispo'],11).'.';
    else
      $message = 'L\'information a été ajoutée.';
    exit($_SESSION['message'] = "{\"etat\":\"ok\",\"message\":\"$message\",\"reload\":\"1\"}");
  }
  exit('{"etat":"nok","message":"L\'information n\'a pas été ajoutée. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');
}

//////////////////////////////////
// Modification des répertoires //
//////////////////////////////////
// Versions 10/11 : autorisé uniquement pour les professeurs de la matière, sans édition
// À rendre possible en fonction des droits d'édition en V12
elseif ( ( $action == 'reps' ) && ( $autorisation == 5 ) && ctype_digit($id = $_REQUEST['id'] ?? '') )  {
  
  // Vérification que l'identifiant est valide
  $resultat = $mysqli->query("SELECT parent, nom, menu, protection, matiere, zip FROM reps WHERE id = $id AND FIND_IN_SET(matiere,'${_SESSION['matieres']}')");
  if ( !$resultat->num_rows )
    exit('{"etat":"nok","message":"Identifiant de répertoire non valide"}');
  $r = $resultat->fetch_assoc();
  $resultat->free();

  // Apparition, avec la protection du répertoire parent
  // (impossible pour les répertoires-racines des matières)
  if ( isset($_REQUEST['montre']) && $r['parent'] )  {
    $resultat = $mysqli->query("SELECT protection FROM reps WHERE id = ${r['parent']}");
    $protectionparent = $resultat->fetch_row()[0];
    $resultat->free();
    if ( $protectionparent == 32 )
      exit('{"etat":"nok","message":"Il est impossible de «&nbsp;montrer&nbsp;» un répertoire qui se trouve dans un répertoire invisible. Mais vous pouvez modifier sa visibilité en cliquant sur le réglage de la protection <span class=\"icon-lock\"></span>."}');
    if ( !requete('reps',"UPDATE reps SET protection = $protectionparent WHERE id = $id OR FIND_IN_SET($id,parents)",$mysqli) )
      exit("{\"etat\":\"nok\",\"message\":\"Le répertoire <em>${r['nom']}</em> n'a pas été rendu visible. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');
    // Modification des documents contenus
    $resultat = $mysqli->query("SELECT GROUP_CONCAT(docs.id) FROM docs WHERE FIND_IN_SET($id,parents)");
    $docs = $resultat->fetch_row()[0];
    $resultat->free();
    if ( $docs )  {
      if ( !requete('docs',"UPDATE docs SET protection = $protectionparent WHERE FIND_IN_SET($id,parents)",$mysqli) || !recents($mysqli,3,explode(',',$docs),array('protection'=>$protectionparent)) )
        exit("{\"etat\":\"nok\",\"message\":\"Le contenu du répertoire <em>${r['nom']}</em> n'a pas été correctement rendu visible. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');
      majmatiere($mysqli,$r['matiere'],true,false);
    }
    // Modification du menu si ce répertoire ou un sous-répertoire y est
    else  {
      $resultat = $mysqli->query("SELECT SUM(menu) FROM reps WHERE id = $id OR FIND_IN_SET($id,parents)");
      if ( $resultat->fetch_row()[0] )
        requete('utilisateurs',"UPDATE utilisateurs SET menuelements='' WHERE FIND_IN_SET(${r['matiere']},menumatieres)",$mysqli);
      $resultat->free();
    }
    exit($_SESSION['message'] = "{\"etat\":\"ok\",\"message\":\"Le répertoire <em>${r['nom']}</em> ainsi que tout son contenu est désormais visible.\",\"reload\":\"1\"}");
  }

  // Disparition
  if ( isset($_REQUEST['cache']) && $r['parent'] )  {
    if ( !requete('reps',"UPDATE reps SET protection = 32 WHERE id = $id OR FIND_IN_SET($id,parents)",$mysqli) )
      exit("{\"etat\":\"nok\",\"message\":\"Le répertoire <em>${r['nom']}</em> n'a pas été caché. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');
    // Modification des documents contenus
    $resultat = $mysqli->query("SELECT GROUP_CONCAT(docs.id) FROM docs WHERE FIND_IN_SET($id,parents)");
    $docs = $resultat->fetch_row()[0];
    $resultat->free();
    if ( $docs )  {
      if ( !requete('docs',"UPDATE docs SET protection = 32, dispo = 0 WHERE FIND_IN_SET($id,parents)",$mysqli) || !recents($mysqli,3,explode(',',$docs),array('protection'=>32)) )
        exit("{\"etat\":\"nok\",\"message\":\"Le contenu du répertoire <em>${r['nom']}</em> n'a pas été correctement caché. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');
      majmatiere($mysqli,$r['matiere'],true,true);
    }
    // Modification du menu si ce répertoire ou un sous-répertoire y est
    else  {
      $resultat = $mysqli->query("SELECT SUM(menu) FROM reps WHERE id = $id OR FIND_IN_SET($id,parents)");
      if ( $resultat->fetch_row()[0] )
        requete('utilisateurs',"UPDATE utilisateurs SET menuelements='' WHERE FIND_IN_SET(${r['matiere']},menumatieres)",$mysqli);
      $resultat->free();
    }
    exit($_SESSION['message'] = "{\"etat\":\"ok\",\"message\":\"Le répertoire <em>${r['nom']}</em> ainsi que tout son contenu est désormais caché.\",\"reload\":\"1\"}");
  }

  // Modification du nom (impossible pour les répertoires-racines des matières)
  if ( ( 'nom' == ( $_REQUEST['champ'] ?? '' ) ) && $r['parent'] )  {
    if ( !($valeur = htmlspecialchars(trim($mysqli->real_escape_string($_REQUEST['val'] ?? '')),ENT_COMPAT) ) )
      exit("{\"etat\":\"nok\",\"message\":\"Le répertoire <em>${r['nom']}</em> n'a pas été modifié. Le nom doit être non vide.\"}");
    if( !requete('reps',"UPDATE reps SET nom = '$valeur' WHERE id = $id",$mysqli)
     || !requete('recents',"UPDATE recents SET texte = ( SELECT CONCAT(ext,'|',taille,'|',d.parent,'|',GROUP_CONCAT( r.nom ORDER BY FIND_IN_SET(r.id,d.parents) SEPARATOR '/' ))
                                                         FROM docs AS d LEFT JOIN reps AS r ON FIND_IN_SET(r.id,d.parents) WHERE d.id = recents.id GROUP BY d.id )
                                           WHERE type = 3 AND id IN (SELECT docs.id FROM docs WHERE FIND_IN_SET($id,parents))",$mysqli) )
      exit("{\"etat\":\"nok\",\"message\":\"Le nom du répertoire <em>${r['nom']}</em> n'a pas été modifié. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');
    rss($mysqli,$r['matiere'],0);
    exit("{\"etat\":\"ok\",\"message\":\"Le nom du répertoire <em>${r['nom']}</em> a été modifié.\"}");
  }

  // Modification des préférences (nom/menu/déplacement) uniquement si pas à la racine
  if ( isset($_REQUEST['nom']) && $r['parent'] && isset($_REQUEST['parent']) )  {
    $action = '';
    
    if ( ( $nom = htmlspecialchars(trim($_REQUEST['nom']),ENT_COMPAT) ) && ( $nom != $r['nom'] ) )  {
      $nom = $mysqli->real_escape_string($nom);
      if ( !requete('reps', "UPDATE reps SET nom = '$nom' WHERE id = $id",$mysqli)
        || !requete('recents',"UPDATE recents SET texte = ( SELECT CONCAT(ext,'|',taille,'|',d.parent,'|',GROUP_CONCAT( r.nom ORDER BY FIND_IN_SET(r.id,d.parents) SEPARATOR '/' ))
                                                            FROM docs AS d LEFT JOIN reps AS r ON FIND_IN_SET(r.id,d.parents) WHERE d.id = recents.id GROUP BY d.id )
                                              WHERE type = 3 AND id IN (SELECT docs.id FROM docs WHERE FIND_IN_SET($id,parents))",$mysqli) )
        exit("{\"etat\":\"nok\",\"message\":\"Le nom du répertoire <em>${r['nom']}</em> n'a pas été modifié. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');
      rss($mysqli,$r['matiere'],0);
      $action = 'renommé';
    }
    
    // Modification de l'apparition dans le menu
    if ( ( $menu = intval(isset($_REQUEST['menurep']))) != $r['menu'] )  {
      if ( !requete('reps', "UPDATE reps SET menu = $menu WHERE id = $id",$mysqli) ) 
        exit("{\"etat\":\"nok\",\"message\":\"Le nom du répertoire <em>${r['nom']}</em> n'a pas été modifié. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');
      requete('utilisateurs',"UPDATE utilisateurs SET menuelements='' WHERE FIND_IN_SET(${r['matiere']},menumatieres)",$mysqli);
      $action = ( $action ) ? 'renommé et marqué au menu' : 'marqué au menu';
    }
  
    // Déplacement du répertoire si $_REQUEST['parent'] non nul et si pas à la racine
    if ( ctype_digit($parent = $_REQUEST['parent']) && !in_array($parent,array(0,$id,$r['parent'])) )  {
      // Vérification du nouveau répertoire parent
      $resultat = $mysqli->query("SELECT parents, matiere FROM reps WHERE id = $parent AND FIND_IN_SET(matiere,'${_SESSION['matieres']}') AND NOT FIND_IN_SET($id,parents)");
      if ( !$resultat->num_rows )
        exit("{\"etat\":\"nok\",\"message\":\"Le répertoire <em>${r['nom']}</em> n'a pas été déplacé. Identifiant de répertoire de destination non valide.\"}");
      $s = $resultat->fetch_assoc();
      $resultat->free();
      $mat = $s['matiere'];
      $parents = "${s['parents']},$parent";
      if ( !requete('reps',"UPDATE reps SET matiere = $mat, parent = $parent, parents = '$parents' WHERE id = $id",$mysqli)
        || !requete('reps',"UPDATE reps SET matiere = $mat, parents = '$parents,$id' WHERE parent = $id",$mysqli)
        || !requete('docs',"UPDATE docs SET matiere = $mat, parents = '$parents,$id' WHERE parent = $id",$mysqli)
        || !requete('recents',"UPDATE recents SET matiere = $mat,
                                                  texte = ( SELECT CONCAT(ext,'|',taille,'|',d.parent,'|',GROUP_CONCAT( r.nom ORDER BY FIND_IN_SET(r.id,d.parents) SEPARATOR '/' ))
                                                            FROM docs AS d LEFT JOIN reps AS r ON FIND_IN_SET(r.id,d.parents) WHERE d.id = recents.id GROUP BY d.id )
                                              WHERE type = 3 AND id IN (SELECT docs.id FROM docs WHERE FIND_IN_SET($id,parents))",$mysqli) )
        exit("{\"etat\":\"nok\",\"message\":\"Le répertoire <em>${r['nom']}</em> n'a pas été déplacé. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');
      // Mise à jour du flux rss pour la ou les deux matières
      rss($mysqli, ( $r['matiere'] != $mat ) ? array($r['matiere'],$mat) : $mat, 0);
      // Mise à jour de la ou des deux matières pour le champ "docs"
      majmatiere($mysqli,$r['matiere'],true,true);
      if ( $mat != $r['matiere'] )
        majmatiere($mysqli,$mat,true,true);
      $action = ( $action ) ? $action.' et déplacé' : 'déplacé';
    }
    
    // Sortie
    if ( $action )
      exit($_SESSION['message'] = "{\"etat\":\"ok\",\"message\":\"Le répertoire <em>${r['nom']}</em> a été $action.\",\"reload\":\"1\"}");
    exit("{\"etat\":\"nok\",\"message\":\"Le répertoire <em>${r['nom']}</em> n'a pas été modifié. Aucune modification demandée.\"}");
  }

  // Modification ou propagation de la protection
  if ( ctype_digit($protection = $_REQUEST['protection'] ?? '') && ctype_digit($zip = $_REQUEST['download'] ?? '') ) {
    // Sans matière, protection non nulle entre 1 et 15, ou 32 
    if ( !$r['matiere'] && $protection ) 
      $protection = ( $protection & 15 ) ?: 32;
    $modif = 0;
    // Pas de modification de la table recents ici : seule la protection du répertoire change
    if ( $r['protection'] != $protection )  {
      if ( !requete('reps', "UPDATE reps SET protection = $protection WHERE id = $id",$mysqli) )
        exit("{\"etat\":\"nok\",\"message\":\"La protection du répertoire <em>${r['nom']}</em> n'a pas été modifiée. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');
      // Modification de la matière si protection du répertoire racine modifié
      if ( !$r['parent'] && $r['matiere'] )
        requete('matieres',"UPDATE matieres SET docs_protection = $protection WHERE id = ${r['matiere']}",$mysqli);
      $modif = 1;
    }
    if ( $r['zip'] != $zip )  {
      if ( !requete('reps', "UPDATE reps SET zip = $zip WHERE id = $id",$mysqli) )
        exit("{\"etat\":\"nok\",\"message\":\"L'autorisation de téléchargement du répertoire <em>${r['nom']}</em> n'a pas été modifiée. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');
      $modif += 2;
    }
    // Propagation des droits d'accès aux sous-répertoires et documents
    if ( isset($_REQUEST['propage']) )  {
      if ( !requete('reps',"UPDATE reps SET protection = $protection, zip = $zip WHERE FIND_IN_SET($id,parents)",$mysqli) )
        exit("{\"etat\":\"nok\",\"message\":\"La protection des sous-répertoires de <em>${r['nom']}</em> n'a pas été modifiée. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');
      // Modification des documents contenus
      $resultat = $mysqli->query("SELECT GROUP_CONCAT(docs.id) FROM docs WHERE FIND_IN_SET($id,parents)");
      $docs = $resultat->fetch_row()[0];
      $resultat->free();
      // Suppression de l'affichage différé si disparition
      if ( $docs && ( !requete('docs',"UPDATE docs SET protection = $protection".( ( $protection == 32 ) ? ', dispo = 0' : '' )." WHERE FIND_IN_SET($id,parents)",$mysqli)
                   || !recents($mysqli,3,explode(',',$docs),array('protection'=>$protection)) ) )
        exit("{\"etat\":\"nok\",\"message\":\"La protection du contenu du répertoire <em>${r['nom']}</em> n'a pas été modifiée. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');
      // Modification plus loin du menu si un sous-répertoire y est
      $resultat = $mysqli->query("SELECT SUM(menu) FROM reps WHERE FIND_IN_SET($id,parents)");
      $r['menu'] = $resultat->fetch_row()[0];
      $resultat->free();
      $modif += 4;
    }
    // Modification de la matière et du menu si des docs ont pu apparaître/disparaître
    // ou si on a modifié la protection du répertoire racine
    if ( ( $docs ?? '' ) || !$r['parent'] || $r['menu'] )
      majmatiere($mysqli,$r['matiere'],true, ( ( $p ?? 0 ) == 32 ) && ( $docs ?? false ) );
    // Message
    switch ( $modif )  {
      case 0 : exit("{\"etat\":\"nok\",\"message\":\"La protection du répertoire <em>${r['nom']}</em> ou de son contenu n'a pas été modifié. Aucune modification demandée.\"}");
      case 1 : exit($_SESSION['message'] = "{\"etat\":\"ok\",\"message\":\"La protection du répertoire <em>${r['nom']}</em> a été modifiée.\",\"reload\":\"1\"}");
      case 2 : exit($_SESSION['message'] = "{\"etat\":\"ok\",\"message\":\"L'autorisation de téléchargement du répertoire <em>${r['nom']}</em> a été modifiée.\",\"reload\":\"1\"}");
      case 3 : exit($_SESSION['message'] = "{\"etat\":\"ok\",\"message\":\"La protection et l'autorisation de téléchargement du répertoire <em>${r['nom']}</em> ont été modifiées.\",\"reload\":\"1\"}");
      default : exit($_SESSION['message'] = "{\"etat\":\"ok\",\"message\":\"La protection et l'autorisation de téléchargement du répertoire <em>${r['nom']}</em> ont été  propagées à l'ensemble de son contenu.\",\"reload\":\"1\"}");
    }
  }
  
  // Suppression du répertoire et de son contenu
  if ( isset($_REQUEST['supprime']) )  {
    if ( !$r['parent'] )
      exit('{"etat":"nok","message":"Les répertoires racine des matières ne sont pas supprimables."}');
    // Suppression du répertoire et de ses sous-répertoires
    if ( !requete('reps',"DELETE FROM reps WHERE id = $id OR FIND_IN_SET($id,parents)",$mysqli) )
      exit("{\"etat\":\"nok\",\"message\":\"Le répertoire <em>${r['nom']}</em> n'a pas été supprimé. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');
    // Suppression de tous les documents situés dans le répertoire et ses sous-répertoires
    $resultat = $mysqli->query("SELECT GROUP_CONCAT(id) FROM docs WHERE FIND_IN_SET($id,parents)");
    $docs = $resultat->fetch_row()[0];
    $resultat->free();
    if ( $docs )  {
      $resultat = $mysqli->query("SELECT lien FROM docs WHERE FIND_IN_SET($id,parents)");
      if ( $resultat->num_rows )  {
        while ( $s = $resultat->fetch_row() )
          exec("rm -rf documents/${s[0]}");
        $resultat->free();
      }
      if ( !requete('docs',"DELETE FROM docs WHERE FIND_IN_SET($id,parents)",$mysqli) || !recents($mysqli,3,explode(',',$docs)) )
        exit("{\"etat\":\"nok\",\"message\":\"Le répertoire <em>${r['nom']}</em> n'a pas été correctement $action. Certains documents sont encore dans la base de données. Vous devriez en informer l'administrateur. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');
    }
    // Mise à jour de la matière et du menu
    majmatiere($mysqli,$r['matiere'],true,true);
    exit("{\"etat\":\"ok\",\"message\":\"Le répertoire <em>${r['nom']}</em> a été supprimé, ainsi que tous ses sous-répertoires et ses documents.\"}");
  }
}

//////////////////////////////////////////////////////
// Suppression partielle du contenu des répertoires //
//////////////////////////////////////////////////////
elseif ( ( $action == 'vide-rep' ) && ( $autorisation == 5 ) && ctype_digit($id = $_REQUEST['id'] ?? '') )  {
  
  // Vérification que l'identifiant est valide
  $resultat = $mysqli->query("SELECT nom, matiere FROM reps WHERE id = $id AND FIND_IN_SET(matiere,'${_SESSION['matieres']}')");
  if ( !$resultat->num_rows )
    exit('{"etat":"nok","message":"Identifiant de répertoire non valide"}');
  $r = $resultat->fetch_assoc();
  $resultat->free();
  $requete = '';

  // Identifiants des éléments à supprimer
  $rids = implode(',',array_filter($_REQUEST['reps'] ?? array(),'ctype_digit'));
  $dids = implode(',',array_filter($_REQUEST['docs'] ?? array(),'ctype_digit'));
  $requete = ( $dids ) ? "parent = $id AND FIND_IN_SET(id,'$dids')" : '';
  $nr = $nd = 0;
  if ( $rids )  {
    // Vérification des répertoires : on ne garde que les enfants directs
    $resultat = $mysqli->query("SELECT GROUP_CONCAT(id) FROM reps WHERE parent = $id AND FIND_IN_SET(id,'$rids')");
    $rids = $resultat->fetch_row()[0];
    $resultat->free();
    // Récupération des sous-répertoires enfants du niveau supérieur
    if ( $rids )  {
      $resultat = $mysqli->query('SELECT GROUP_CONCAT(id) FROM reps WHERE '. implode(' OR ', array_map(function($r) { return "FIND_IN_SET($r,parents)"; }, explode(',',$rids) ) ) );
      if ( $rids2 = $resultat->fetch_row()[0] )
        $rids .= ",$rids2";
      $resultat->free();
      // Suppression des sous-répertoires dans la base
      if ( !requete('reps',"DELETE FROM reps WHERE FIND_IN_SET(id,'$rids')",$mysqli) )
        exit("{\"etat\":\"nok\",\"message\":\"Les sous-répertoires de <em>${r['nom']}</em> qui devaient être supprimés ne l'ont pas été. Vous devriez signaler ce problème à l'administrateur. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');
      $requete .= ( $dids ) ? " OR FIND_IN_SET(parent,'$rids')" : "FIND_IN_SET(parent,'$rids')";
      $nr = count(explode(',',$rids));
    }
  }
  
  // Récupération des documents concernés
  if ( $requete )  {
    $resultat = $mysqli->query("SELECT GROUP_CONCAT(id) FROM docs WHERE $requete");
    $dids = $resultat->fetch_row()[0];
    $resultat->free();
  }
  if ( !($nr+strlen($dids)) )
    exit('{"etat":"nok","message":"Aucun répertoire/document à supprimer"}');
  // Suppression des documents
  if ( $dids )  {
    $resultat = $mysqli->query("SELECT lien FROM docs WHERE FIND_IN_SET(id,'$dids')");
    if ( $resultat->num_rows )  {
      while ( $s = $resultat->fetch_row() )
        exec("rm -rf documents/${s[0]}");
      $resultat->free();
    }
    if ( !requete('docs',"DELETE FROM docs WHERE FIND_IN_SET(id,'$dids')",$mysqli) || !recents($mysqli,3,explode(',',$dids)) )
      exit("{\"etat\":\"nok\",\"message\":\"Le contenu sélectionné dans le répertoire <em>${r['nom']}</em> n'a pas été correctement supprimé. Certains documents sont encore dans la base de données. Vous devriez signaler ce problème à l'administrateur. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');
    $nd = count(explode(',',$dids));
  }
  // Mise à jour de la matière et du menu
  majmatiere($mysqli,$r['matiere'],true,true);
  # Rechargement
  $contenu = '';
  if ( $nr )
    $contenu = "$nr sous-répertoire".( $nr > 1 ? 's' : '' );
  if ( $nd )
    $contenu .= ( $nr ? ' et ' : '') . "$nd document".( $nd > 1 ? 's' : '' );
  $contenu .= ( $nr + $nd == 1 ) ? ' a été supprimé' : ' ont été supprimés';
  exit($_SESSION['message'] = "{\"etat\":\"ok\",\"message\":\"$contenu dans le répertoire <em>${r['nom']}</em>.\",\"reload\":\"1\"}");
}

///////////////////////////
// Ajout d'un répertoire //
///////////////////////////
// Versions 10/11 : autorisé uniquement pour les professeurs de la matière, sans édition
// À rendre possible en fonction des droits d'édition en V12
elseif ( ( $action == 'ajout-rep' ) && ( $autorisation == 5 ) && ctype_digit($protection = $_REQUEST['protection'] ?? '') && ctype_digit($parent = $_REQUEST['id'] ?? '') && ( $nom = htmlspecialchars(trim($_REQUEST['nom'] ?? ''),ENT_COMPAT) ) )  {
  // Vérification du répertoire parent
  $resultat = $mysqli->query("SELECT parents, matiere, protection, menu FROM reps WHERE id = $parent AND FIND_IN_SET(matiere,'${_SESSION['matieres']}')");
  if ( !$resultat->num_rows )
    exit('{"etat":"nok","message":"Identifiant de répertoire parent non valide"}');
  $r = $resultat->fetch_assoc();
  $resultat->free();
  $menu = intval(isset($_REQUEST['menurep']));
  // Sans matière, protection non nulle entre 1 et 15, ou 32 
  if ( !$r['matiere'] && $protection ) 
    $protection = ( $protection & 15 ) ?: 32;
  if ( !requete('reps',"INSERT INTO reps SET parent = $parent, parents = '${r['parents']},$parent', nom = '".$mysqli->real_escape_string($nom)."', matiere = ${r['matiere']}, protection = $protection, menu = $menu",$mysqli) )
    exit('{"etat":"nok","message":"Le répertoire n\'a pas été ajouté. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');
  // Mise à jour du menu pour les utilisateurs de la matière
  if ( $menu )
    $mysqli->query("UPDATE utilisateurs SET menuelements='' WHERE FIND_IN_SET(${r['matiere']},menumatieres)");
  exit($_SESSION['message'] = "{\"etat\":\"ok\",\"message\":\"Le répertoire <em>$nom</em> a été ajouté.\",\"reload\":\"1\"}");
}

////////////////////////////////
// Modification des documents //
////////////////////////////////
// Versions 10/11 : autorisé uniquement pour les professeurs de la matière, sans édition
// À rendre possible en fonction des droits d'édition en V12
elseif ( ( ( $action == 'docs' ) || ( $action == 'maj-doc' ) ) && ( $autorisation == 5 ) && ctype_digit($id = $_REQUEST['id'] ?? '') )  {
  // Vérification de l'identifiant
  $resultat = $mysqli->query("SELECT nom, protection, ext, lien, parent, matiere, IF(dispo,DATE_FORMAT(dispo,'%Y-%m-%d %H:%i'),0) AS dispo
                              FROM docs WHERE id = $id AND FIND_IN_SET(matiere,'${_SESSION['matieres']}')");
  if ( !$resultat->num_rows )
    exit('{"etat":"nok","message":"Identifiant de document non valide"}');
  $r = $resultat->fetch_assoc();
  $resultat->free();

  // Apparition, avec la protection du répertoire parent
  // L'affichage ne peut pas être différé ici (il ne l'est pas quand le doc est caché)
  if ( isset($_REQUEST['montre']) )  {
    $resultat = $mysqli->query("SELECT protection FROM reps WHERE id = ${r['parent']}");
    $protection = $resultat->fetch_row()[0];
    $resultat->free();
    if ( $protection == 32 )
      exit('{"etat":"nok","message":"Il est impossible de «&nbsp;montrer&nbsp;» un document qui se trouve dans un répertoire invisible. Mais vous pouvez modifier sa visibilité en cliquant sur le réglage de la protection <span class=\"icon-lock\"></span>."}');
    if ( !requete('docs',"UPDATE docs SET protection = $protection WHERE id = $id",$mysqli) || !recent($mysqli,3,$id,array('protection'=>$protection)) )
      exit("{\"etat\":\"nok\",\"message\":\"$message Le document <em>${r['nom']}</em> n'a pas été rendu visible. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');
    majmatiere($mysqli,$r['matiere'],true,false);
    exit($_SESSION['message'] = "{\"etat\":\"ok\",\"message\":\"Le document <em>${r['nom']}</em> est désormais visible.\",\"reload\":\"1\"}");
  }

  // Disparition
  // Supprime l'éventuel affichage différé
  if ( isset($_REQUEST['cache']) )  {
    if ( !requete('docs',"UPDATE docs SET protection = 32, dispo = 0 WHERE id = $id",$mysqli) || !recent($mysqli,3,$id,array('protection'=>32)) )
      exit("{\"etat\":\"nok\",\"message\":\"Le document <em>${r['nom']}</em> n'a pas été caché. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');
    majmatiere($mysqli,$r['matiere'],true,true);
    exit($_SESSION['message'] = "{\"etat\":\"ok\",\"message\":\"Le document <em>${r['nom']}</em> est désormais invisible.\",\"reload\":\"1\"}");
  }

  // Modification du nom
  if ( 'nom' == ( $_REQUEST['champ'] ?? '' ) )  {
    if ( !( $nom = htmlspecialchars(trim($_REQUEST['val'] ?? ''),ENT_COMPAT) ) )
      exit("{\"etat\":\"nok\",\"message\":\"Le nom du document <em>${r['nom']}</em> n'a pas été modifié. Le nom doit être non vide.\"}");
    setlocale(LC_CTYPE, "fr_FR.UTF-8");
    if ( $r['ext'] == strtolower(substr( ( $ext = strrchr($nom,'.') ?: '' ),1,10)) )
      $nom = str_replace(".$ext",'',$nom);
    // real_escape_string seulement pour la requête SQL
    $nouveau_nom = $mysqli->real_escape_string($nom = substr(basename(str_replace(array('\\','/'),'_',$nom)),0,100));
    if ( $nom == $r['nom'] ) 
      exit("{\"etat\":\"nok\",\"message\":\"Le nom du document <em>${r['nom']}</em> n'a pas été modifié. Aucune modification demandée.\"}");
    if ( !requete('docs',"UPDATE docs SET nom = '$nouveau_nom', nom_nat = '".zpad($nouveau_nom)."' WHERE id = $id",$mysqli) )
      exit("{\"etat\":\"nok\",\"message\":\"Le nom du document <em>${r['nom']}</em> n'a pas été modifié. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');
    exec('mv documents/'.escapeshellarg("${r['lien']}/${r['nom']}".( $r['ext'] ? ".${r['ext']}" : '')).' documents/'.escapeshellarg("${r['lien']}/$nom".( $r['ext'] ? ".${r['ext']}" : '')));
    recent($mysqli,3,$id,array('titre'=>$nouveau_nom));
    exit("{\"etat\":\"ok\",\"message\":\"Le nom du document <em>${r['nom']}</em> a été modifié.\"}");
  }
  
  // Modification de la protection et de la date de disponibilité
  if ( ctype_digit($protection = $_REQUEST['protection'] ?? '') )  {
    $requete = $modifications = array();
    // Sans matière, protection non nulle entre 1 et 15, ou 32 
    if ( !$r['matiere'] && $protection ) 
      $protection = ( $protection & 15 ) ?: 32;
    // Impossible de cacher et mettre une date de disponibilité simultanément
    if ( ( $protection == 32 ) && isset($_REQUEST['dispo']) )
      exit("{\"etat\":\"nok\",\"message\":\"Il n'est pas possible de régler la date de disponiblité du document <em>${r['nom']}</em> car il est invisible. Vous devez sélectionner des possibilités d'accès.\"}");
    // Modifications de la protection
    if ( $r['protection'] != $protection )  {
      // Disparition
      if ( $protection == 32 )  {
        if ( requete('docs',"UPDATE docs SET protection = 32, dispo = 0 WHERE id = $id",$mysqli) && recent($mysqli,3,$id,array('protection'=>32)) )  {
          majmatiere($mysqli,$r['matiere'],true,true);
          exit($_SESSION['message'] = "{\"etat\":\"ok\",\"message\":\"Le document <em>${r['nom']}</em> est désormais invisible.\",\"reload\":\"1\"}");
        } 
        exit("{\"etat\":\"nok\",\"message\":\"Le document <em>${r['nom']}</em> n'a pas été caché. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');
      }
      $requete[] = "protection = $protection";
      $modifications['protection'] = $protection;
    }
    // Modification de la date de disponibilité 
    if ( isset($_REQUEST['dispo']) )  {
      if ( $dispo = preg_filter('/(\d{2})\/(\d{2})\/(\d{4}) (\d{1,2})h(\d{2})/','$3-$2-$1 $4:$5',$_REQUEST['dispo']) )  {
        if ( strlen($dispo) == 15 )
          $dispo = substr($dispo,0,-4).'0'.substr($dispo,-4);
        if ( $r['dispo'] != $dispo )  {
          // Modification uniquement si dispo est dans le futur
          // Cette erreur empêche la modification de protection
          if ( $dispo < date('Y-m-d H:i') )
            exit("{\"etat\":\"nok\",\"message\":\"La date de disponiblité du document <em>${r['nom']}</em> n'a pas été modifiée car la valeur donnée est déjà passée.\"}");
          $requete[] = "dispo = '$dispo'";
          $modifications['publi'] = $dispo;
        }
      }
      // Suppression de l'affichage différé si existant et dans le futur
      elseif ( $r['dispo'] > date('Y-m-d H:i') )  {
        $requete[] = "dispo = NOW()";
        $modifications['publi'] = date('Y-m-d H:i');
      }
    }
    // Construction de la requête
    if ( $modifications )  {
      if ( requete('docs','UPDATE docs SET '.implode(', ',$requete)." WHERE id = $id",$mysqli) && recent($mysqli,3,$id,$modifications) )  {
        // Mise à jour du menu si modification de la protection
        if ( isset($modifications['protection']) )
          majmatiere($mysqli,$r['matiere'],true,false);
        exit($_SESSION['message'] = "{\"etat\":\"ok\",\"message\":\"L'accès au document <em>${r['nom']}</em> a été modifié.\",\"reload\":\"1\"}");
      }
      exit("{\"etat\":\"nok\",\"message\":\"L'accès au document <em>${r['nom']}</em> n'a pas été modifié. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');
    }
    exit("{\"etat\":\"nok\",\"message\":\"L'accès au document <em>${r['nom']}</em> n'a pas été modifié : aucune modification demandée.\"}");
  }
    
  // Mise à jour d'un document
  if ( isset($_FILES['fichier']) && is_uploaded_file($_FILES['fichier']['tmp_name']) )  {
    // Changement d'extension interdit
    if ( $r['ext'] != ( strtolower(substr(strrchr($_FILES['fichier']['name'],'.'),1,10)) ?: '' ) )
      exit("{\"etat\":\"nok\",\"message\":\"Le document <em>${r['nom']}</em> n'a pas été mis à jour. Le fichier envoyé est d'une extension différente.\"}");
    // Gestion de la taille
    $taille = ( ( $taille = intval($_FILES['fichier']['size']/1024) ) < 1024 ) ? "$taille&nbsp;ko" : intval($taille/1024).'&nbsp;Mo';
    // Déplacement du document uploadé au bon endroit
    if ( !move_uploaded_file($_FILES['fichier']['tmp_name'],"documents/${r['lien']}/${r['nom']}".( $r['ext'] ? ".${r['ext']}" : '')) )
      exit("{\"etat\":\"nok\",\"message\":\"Le document <em>${r['nom']}</em> n'a pas été mis à jour : problème d'écriture du fichier. Vous devriez en informer l'administrateur.\"}");
    // Modifications dans la base de données
    // Mise à jour de la date seulement si document déjà disponible
    $majpubli = ( ( isset($_REQUEST['publi']) && ( $r['dispo'] < date('Y-m-d H:i') ) ) ? 'upload = NOW(),' : '' );
    if ( !requete('docs',"UPDATE docs SET $majpubli taille = '$taille' WHERE id = $id",$mysqli) )
      exit("{\"etat\":\"nok\",\"message\":\"Le document <em>${r['nom']}</em> n'a pas été mis à jour. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');
    requete('recents','UPDATE recents SET '.( $majpubli ? 'maj = NOW(), ' : '' )."texte = CONCAT(SUBSTRING_INDEX(texte,'|',1),'|$taille|',SUBSTRING_INDEX(texte,'|',-2)) WHERE type = 3 AND id = $id",$mysqli);
    if ( $r['protection'] != 32 )
      rss($mysqli,$r['matiere'],$r['protection']);
    exit($_SESSION['message'] = "{\"etat\":\"ok\",\"message\":\"Le document <em>${r['nom']}</em> a été mis à jour.\",\"reload\":\"1\"}");
  }

  // Modification des préférences (nom/déplacement)
  if ( ( $nom = htmlspecialchars(trim($_REQUEST['nom'] ?? ''),ENT_COMPAT) ) && isset($_REQUEST['parent']) )  {
    $action = '';
    
    // Modification du nom
    setlocale(LC_CTYPE, "fr_FR.UTF-8");
    if ( $r['ext'] == strtolower(substr( ( $ext = strrchr($nom,'.') ?: '' ),1,10)) )
      $nom = str_replace(".$ext",'',$nom);
    // real_escape_string seulement pour la requête SQL
    $nouveau_nom = $mysqli->real_escape_string($nom = substr(basename(str_replace(array('\\','/'),'_',$nom)),0,100));
    if ( $nom != $r['nom'] )  {
      // real_escape_string seulement pour la requête SQL
      $nouveau_nom = $mysqli->real_escape_string($nom);
      if ( !requete('docs',"UPDATE docs SET nom = '$nouveau_nom', nom_nat = '".zpad($nouveau_nom)."' WHERE id = $id",$mysqli) )
        exit("{\"etat\":\"nok\",\"message\":\"Le nom du document <em>${r['nom']}</em> n'a pas été modifié. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');
      exec('mv documents/'.escapeshellarg("${r['lien']}/${r['nom']}".( $r['ext'] ? ".${r['ext']}" : '')).' documents/'.escapeshellarg("${r['lien']}/$nom".( $r['ext'] ? ".${r['ext']}" : '')));
      recent($mysqli,3,$id,array('titre'=>$nouveau_nom));
      $action = 'renommé';
    }
    
    // Déplacement dans un autre répertoire
    if ( ctype_digit($parent = $_REQUEST['parent']) && $parent && ( $parent != $r['parent'] ) )  {
      // Vérification du nouveau répertoire parent
      $resultat = $mysqli->query("SELECT parents, matiere FROM reps WHERE id = $parent AND FIND_IN_SET(matiere,'${_SESSION['matieres']}')");
      if ( !$resultat->num_rows )
        exit("{\"etat\":\"nok\",\"message\":\"Le document <em>${r['nom']}</em> n'a pas été déplacé. Identifiant de répertoire parent non valide.\"}");
      $s = $resultat->fetch_assoc();
      $resultat->free();
      $matiere = $s['matiere'];
      if ( !requete('docs',"UPDATE docs SET parent = '$parent', parents = '${s['parents']},$parent', matiere = $matiere WHERE id = $id",$mysqli) 
        || !requete('recents',"UPDATE recents SET matiere = $matiere,
                                                  texte = ( SELECT CONCAT(ext,'|',taille,'|',d.parent,'|',GROUP_CONCAT( r.nom ORDER BY FIND_IN_SET(r.id,d.parents) SEPARATOR '/' ))
                                                            FROM docs AS d LEFT JOIN reps AS r ON FIND_IN_SET(r.id,d.parents) WHERE d.id = $id )
                                              WHERE type = 3 AND id = $id",$mysqli) )
        exit("{\"etat\":\"nok\",\"message\":\"Le document <em>${r['nom']}</em> n'a pas été déplacé. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');
      // Mises à jour des récents, mise à jour des flux rss, mise à jour du menu
      if ( $r['protection'] != 32 )  {
        if ( $matiere == $r['matiere'] )
          rss($mysqli,$r['matiere'],$r['protection']);
        else  {
          rss($mysqli,array($r['matiere'],$matiere),$r['protection']);
          majmatiere($mysqli,$r['matiere'],true,true);
          majmatiere($mysqli,$matiere,true,$r['protection'] == 32);
        }
      }
      $action = ( $action ) ? 'renommé et déplacé' : 'déplacé';
    }
    
    // Message
    if ( $action )
      exit($_SESSION['message'] = "{\"etat\":\"ok\",\"message\":\"Le document <em>${r['nom']}</em> a été $action.\",\"reload\":\"1\"}");
    exit("{\"etat\":\"nok\",\"message\":\"Le document <em>${r['nom']}</em> n'a pas été modifié. Aucune modification demandée.\"}");
  }
  
  // Suppression d'un document
  if ( isset($_REQUEST['supprime']) )  {
    if ( !requete('docs',"DELETE FROM docs WHERE id = $id",$mysqli) || !recent($mysqli,3,$id) )
      exit("{\"etat\":\"nok\",\"message\":\"Le document <em>${r['nom']}</em> n'a pas été supprimé. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'».');
    // Suppression physique
    exec("rm -rf documents/${r['lien']}");
    // Mise à jour de l'affichage de la matière dans le menu
    if ( $r['protection'] != 32 )
      majmatiere($mysqli,$r['matiere'],true,true);
    // Si répertoire vide, obliger le rechargement
    $resultat = $mysqli->query("SELECT id FROM docs WHERE parent = ${r['parent']}");
    if ( $resultat->num_rows )  {
      $resultat->free();
      exit("{\"etat\":\"ok\",\"message\":\"Le document <em>${r['nom']}</em> a été supprimé.\"}");
    }
    exit($_SESSION['message'] = "{\"etat\":\"ok\",\"message\":\"Le document <em>${r['nom']}</em> a été supprimé.\",\"reload\":\"1\"}");
  }
}

////////////////////////
// Ajout de documents //
////////////////////////
// Versions 10/11 : autorisé uniquement pour les professeurs de la matière, sans édition
// À rendre possible en fonction des droits d'édition en V12
elseif ( ( $action == 'ajout-doc' ) && ( $autorisation == 5 ) && isset($_FILES['fichier']) && ctype_digit($protection = $_REQUEST['protection'] ?? '') && ctype_digit($parent = $_REQUEST['id'] ?? '') )  {
//elseif ( ( $action == 'ajout-doc' ) && isset($_FILES['fichier']) && isset($_REQUEST['protection']) && ctype_digit($protection = $_REQUEST['protection']) && isset($_REQUEST['edition']) && ctype_digit($edition = $_REQUEST['edition']) && isset($_REQUEST['id']) && ctype_digit($parent = $_REQUEST['id']) )  {
 

  // Vérification de l'identifiant du répertoire parent
  //$resultat = $mysqli->query("SELECT parents, matiere, edition, FIND_IN_SET(matiere,'${_SESSION['matieres']}') AS matiereassociee FROM reps WHERE id = $parent");
  $resultat = $mysqli->query("SELECT parents, matiere FROM reps WHERE id = $parent");
  if ( !$resultat->num_rows )
    exit('{"etat":"nok","message":"Identifiant de répertoire parent non valide"}');
  $r = $resultat->fetch_assoc();
  $resultat->free();
  // Vérification de l'accès : professeurs de la matière ou édition autorisée sur le répertoire parent
  //if ( !( ( $autorisation == 5 ) && ( $r['matiereassociee'] || ( $r['edition'] > 16 ) ) || ( $autorisation < 5 ) && $r['matiereassociee'] && $r['edition'] && ( ($r['edition']-1)>>($autorisation-1) & 1 ) ) )
  //  exit('{"etat":"nok","message":"Identifiant de répertoire parent non valide"}');

  // Vérification de l'affichage différé et de la date de disponibilité, seulement si dans le futur
  if ( !isset($_REQUEST['affdiff']) || ( $protection == 32 ) || is_null( $dispo = preg_filter('/(\d{2})\/(\d{2})\/(\d{4}) (\d{1,2})h(\d{2})/','$3-$2-$1 $4:$5',$_REQUEST['dispo'] ?? '') ) || ($dispo < date('Y-m-d H:i') ) )
    $dispo = 0;
  // Sans matière, protection non nulle entre 1 et 15, ou 32 
  if ( !$r['matiere'] && $protection ) 
    $protection = ( $protection & 15 ) ?: 32;
  // Traitement de chaque fichier envoyé
  setlocale(LC_CTYPE, "fr_FR.UTF-8");
  $ok = 0;
  $message = '';
  for ( $i = 0 ; $i < ( $n = count($_FILES['fichier']['tmp_name']) ) ; $i++ )  {
    // Vérifications des données envoyées (on fait confiance aux utilisateurs connectés pour ne pas envoyer de scripts malsains)
    // $ext doit être en minusculte et ne pas dépasser 10 caractères, mais modification à l'écriture du fichier
    $ext = substr(strtolower(htmlspecialchars( strrchr($_FILES['fichier']['name'][$i],'.') ,ENT_COMPAT)),1,10);
    if ( !($nom = substr(htmlspecialchars(trim(basename(str_replace(array("$ext",'\\','/'),array('','_','_'), ( $_REQUEST['nom'][$i] ?? '' ) ?: $_FILES['fichier']['name'][$i] ))),ENT_COMPAT),0,100)) )  {
      $message .= '<br>Le document <em>'.$_FILES['fichier']['tmp_name'][$i].'</em>  n\'a pas été ajouté, vous n\'avez pas précisé de nom et ce n\'est pas autorisé.';
      continue;
    }
    if ( !is_uploaded_file($_FILES['fichier']['tmp_name'][$i]) )  {
      $message .= '<br>Le document <em>'.$_FILES['fichier']['tmp_name'][$i].'</em> n\'a pas été ajouté : le fichier a mal été envoyé. Vous devriez en informer l\'administrateur.';
      continue;
    }
    // Création du répertoire particulier
    $lien = substr(sha1(mt_rand()),0,15);
    while ( is_dir("documents/$lien") )
      $lien = substr(sha1(mt_rand()),0,15);
    mkdir("documents/$lien");
    // Gestion de la taille
    $taille = ( ( $taille = intval($_FILES['fichier']['size'][$i]/1024) ) < 1024 ) ? "$taille&nbsp;ko" : intval($taille/1024).'&nbsp;Mo';
    // Déplacement du document uploadé au bon endroit
    if ( !move_uploaded_file($_FILES['fichier']['tmp_name'][$i],"documents/$lien/$nom".( $ext ? ".$ext" : '' ) ) )  {
      $message .= "<br>Le document <em>$nom/em> n'a pas été ajouté : problème d'écriture du fichier. Vous devriez en informer l'administrateur.";
      continue;
    }
    // Écriture MySQL
    // On doit garder $nom pour l'affichage
    $nom_sql = $mysqli->real_escape_string($nom);
    if ( requete('docs',"INSERT INTO docs SET parent = $parent, parents = '${r['parents']},$parent', matiere = ${r['matiere']},
                         nom = '$nom_sql', nom_nat = '".zpad($nom_sql)."', upload = CURDATE(), taille = '$taille',
                         lien = '$lien', ext='".$mysqli->real_escape_string($ext)."', protection = $protection, dispo = '$dispo'",$mysqli) )  {
      recent($mysqli,3,$mysqli->insert_id,true);
      $ok++;
    }
    else  {
      // Retour en arrière
      exec("rm -rf documents/$lien");
      $message .= "<br>Le document <em>$nom</em> n'a pas été ajouté. Erreur MySQL n°".$mysqli->errno.', «&nbsp;'.$mysqli->error.'&nbsp;».';
    }
  }
  // Traitement des échecs 
  if ( !$ok )
    exit("{\"etat\":\"nok\",\"message\":\"Aucun document n'a été envoyé.$message\"}");
  // Mise à jour de l'affichage de la matière dans le menu
  majmatiere($mysqli,$r['matiere'],true,$protection == 32);
  // Gestion du retour : fin du message fonction de la disponibilité
  $s = ( $n > 1 ) ? 's' : '';
  if ( $protection == 32 )
    $message = "été ajouté$s mais reste".($n>1?'nt':'')." invisible$s pour l'instant.$message";
  elseif ( $dispo )
    $message = "été ajouté$s mais ne ser".($n>1?'ont':'a')." visible$s que le ".substr($_REQUEST['dispo'],0,10).' à '.substr($_REQUEST['dispo'],11).".$message";
  else
    $message = "été ajouté$s.$message";
  // Envoi du message
  if ( $n == 1 )
    exit($_SESSION['message'] = "{\"etat\":\"ok\",\"message\":\"Le document <em>$nom</em> a $message\",\"reload\":\"1\"}");
  if ( $ok < $n )
    exit($_SESSION['message'] = "{\"etat\":\"ok\",\"message\":\"Seuls $ok documents sur $n ont $message\",\"reload\":\"1\"}");
  exit($_SESSION['message'] = "{\"etat\":\"ok\",\"message\":\"Les $ok documents ont $message.\",\"reload\":\"1\"}");
}

/////////////////////////////////////////////
// Modification d'un événement de l'agenda //
/////////////////////////////////////////////
elseif ( ( $action == 'agenda' ) && ctype_digit($id = $_REQUEST['id'] ?? '') )  {

  // Vérification de l'identifiant et récupération des données
  $resultat = $mysqli->query("SELECT matiere, type, DATE_FORMAT(debut,'%Y-%m-%d %H:%i') AS debut, DATE_FORMAT(fin,'%Y-%m-%d %H:%i') AS fin, texte, protection, edition, index_aff,
                              IF(dispo,DATE_FORMAT(dispo,'%Y-%m-%d %H:%i'),0) AS dispo, FIND_IN_SET(matiere,'${_SESSION['matieres']}') AS matiereassociee
                              FROM agenda WHERE id = $id");
  if ( !$resultat->num_rows )
    exit('{"etat":"nok","message":"Identifiant d\'événement non valide"}');
  $r = $resultat->fetch_assoc();
  $resultat->free();
  // Récupération des préférences globales de protection/édition de l'agenda
  // Préférences : agenda_edition, agenda_protection
  $resultat = $mysqli->query('SELECT GROUP_CONCAT(val ORDER BY nom) FROM prefs WHERE nom LIKE \'agenda%ion\'');
  list($edition_agenda, $protection_agenda) = explode(',',$resultat->fetch_row()[0]);
  $resultat->free();
  // Si fonctionnalité désactivée
  if ( $protection_agenda == 32 )
    exit('{"etat":"nok","message":"La fonctionnalité agenda a été désactivée. Vous devez la réactiver sur la page de gestion des réglages."}');
  
  // Vérification de l'accès
  // * les administrateurs peuvent tout faire
  // * les professeurs de la matière peuvent tout faire
  // * les éditeurs de l'événement peuvent éditer titre et texte
  // * les éditeurs de l'agenda seulement ne peuvent rien faire
  // * les éditeurs de l'événement et de l'agenda peuvent tout faire
  if ( $_SESSION['admin'] || ( $autorisation == 5 ) && $r['matiereassociee'] )
    $editionglobale = true;
  // Pour les utilisateurs autorisés à l'édition de l'information, professeurs hors de la matière
  // ou autres utilisateurs associés à la matière, seuls titre et texte sont modifiables
  elseif ( $r['edition'] && ( ($r['edition']-1)>>($autorisation-1) & 1 ) && ( ( $autorisation == 5 ) || $r['matiereassociee'] ) )
    // Ils peuvent tout faire si droit d'édition sur l'agenda
    $editionglobale = $edition_agenda && ( ($edition_agenda-1)>>($autorisation-1) & 1 );
  // Cas des professeurs-colleurs
  elseif ( $r['edition'] && ( $autorisation == 5 ) && strpos($_SESSION['matieres'],'c') && in_array("c${r['matiere']}",explode(',',$_SESSION['matieres'])) && ( ($r['edition']-1) & 4 ) )
    $editionglobale = $edition_agenda && ( ($edition_agenda-1) & 4 );
  else
    exit('{"etat":"nok","message":"Identifiant d\'événement non valide"}');
 
  // Traitement d'une modification de texte
  if ( isset($_REQUEST['champ']) && $_REQUEST['champ'] == 'texte' )  {
    if ( !( $texte = trim($mysqli->real_escape_string($_REQUEST['val'] ?? '')) ) )
      exit('{"etat":"nok","message":"L\'événement n\'a pas été modifié. Le texte doit être non vide."}');
    exit( requete('agenda',"UPDATE agenda SET texte = '$texte' WHERE id = $id",$mysqli) && recent($mysqli,4,$id,array('texte'=>$texte),isset($_REQUEST['publi']))
       ? '{"etat":"ok","message":"Le texte de l\'événement a été modifié."}'
       : '{"etat":"nok","message":"L\'événement n\'a pas été modifié. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');
  }
  
  // Traitement d'une modification du "titre" : matière, type, dates-heures
  if ( ctype_digit($tid = $_REQUEST['tid'] ?? '') && ctype_digit($mid = $_REQUEST['mid'] ?? '') )  {
    
    $requete = $modifrecent = array();
    // Validation des dates
    if ( !( $debut = valide_date('debut') ) || ( $debut > ( $fin = valide_date('fin') ) ) )
      exit('{"etat":"nok","message":"Les dates/heures choisies ne sont pas valables."}');
    if ( ( $debut != $r['debut'] ) || ( $fin != $r['fin'] ) )  {
      // Validation des dates : l'événement doit se trouver au moins en partie
      // dans l'année scolaire
      $resultat = $mysqli->query("SELECT id FROM semaines WHERE debut <= '$fin' AND debut >= SUBDATE('$debut',80) LIMIT 1");
      if ( !$resultat->num_rows )
        exit('{"etat":"nok","message":"Les dates de l\'événement le placent hors de l\'année scolaire."}');
      $resultat->free();
      // Modification
      if ( $debut != $r['debut'] )
        $requete[] = "debut = '$debut'";
      if ( $fin != $r['fin'] )
        $requete[] = "fin = '$fin'";
    }
    // Vérification du changement de type (non nul) ou de matière (peut-être nulle)
    if ( ( $debut != $r['debut'] ) || ( $tid != $r['type'] ) || ( $mid != $r['matiere'] ) )  {
      // Récupération des noms du type et de la matière pour les infos récentes
      $resultat = $mysqli->query("SELECT nom FROM `agenda-types` WHERE id = $tid");
      if ( !$resultat->num_rows )
        exit('{"etat":"nok","message":"Type d\'événement non valide."}');
      $type = $mysqli->real_escape_string($resultat->fetch_row()[0]);
      $resultat->free();
      if ( $mid )  {
        $resultat = $mysqli->query("SELECT nom FROM matieres WHERE id = $mid");
        // Seuls les administrateurs ou les profs/éditeurs associés à la matière peuvent écrire
        // Dernière partie : cas des professeurs-colleurs
        if ( !( $resultat->num_rows && ( $_SESSION['admin'] || in_array($mid,explode(',',$_SESSION['matieres'])) || 
             ( $autorisation == 5 ) && strpos($_SESSION['matieres'],'c') && in_array("c$mid",explode(',',$_SESSION['matieres'])) && ( ($edition_agenda-1) & 4 )
                                       ) ) )
          exit('{"etat":"nok","message":"Matière non valide."}');
        $matiere = ' en '.$mysqli->real_escape_string($resultat->fetch_row()[0]);
        $resultat->free();
      }
      $jour = substr($debut,8,2);
      $mois = substr($debut,5,2);
      $annee = substr($debut,2,2);
      $modifrecent['titre'] = "$jour/$mois - $type". ( $matiere ?? '' );
      if ( $debut != $r['debut'] )
        $modifrecent['lien'] = "agenda?mois=$annee$mois";
      if ( $tid != $r['type'] )
        $requete[] = "type = $tid";
      if ( $mid != $r['matiere'] )  {
        $requete[] = "matiere = $mid";
        $modifrecent['matiere'] = $mid;
      }
    }
    // Écriture dans la base de données
    if ( !$requete )
      exit('{"etat":"nok","message":"L\'événement n\'a pas été modifié : aucune modification demandée."}');
    if ( requete('agenda','UPDATE agenda SET '.implode(', ',$requete)." WHERE id = $id", $mysqli) )  {
      if ( $modifrecent )
        recent($mysqli,4,$id,$modifrecent);
      exit($_SESSION['message'] = '{"etat":"ok","message":"L\'événement a été modifié.","reload":"1"}');
    }
    exit('{"etat":"nok","message":"L\'événement n\'a pas été modifié. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');
  }

  // Autres modifications, nécessitant le droit d'édition sur l'agenda
  if ( $editionglobale )  {

    // Droits d'accès et d'édition ; affichage sur la page d'accueil
    if ( ctype_digit($protection = $_REQUEST['protection'] ?? '') && ctype_digit($edition = $_REQUEST['edition'] ?? '') )  {
      // La protection de l'événement et la protection de l'agenda sont
      // indépendantes l'une de l'autre. Un utilisateur peut voir l'événement sur
      // les événements récents sans la voir sur l'agenda. 
      // Sans matière, protection non nulle entre 1 et 15, ou 32 
      if ( !$r['matiere'] && $protection ) 
        $protection = ( $protection & 15 ) ?: 32;
      // Validation de l'édition. Les deux protections (agenda et événement)
      // doivent obligatoirement inclure l'édition.
      $edition = ( $edition ) ? ($edition = ($edition-1) & (32-($protection?:1)) & (32-($protection_agenda?:1)) & 30) + ($edition>0) : 0;
      // Validation de l'affichage en page d'accueil
      $index_aff = intval(isset($_REQUEST['index_aff']));
      // Pas de modification
      if ( ( $protection == $r['protection'] ) && ( $edition == $r['edition'] ) && ( $index_aff == $r['index_aff'] ) )
        exit('{"etat":"nok","message":"Le réglage d\'affichage de l\'événement n\'a pas été modifié : aucune modification demandée."}');
      // Suppression de l'affichage différé si invisible
      if ( $protection != $r['protection'] )  {
        if ( !requete('agenda',"UPDATE agenda SET protection = $protection" . ( ( $protection == 32 ) ? ', dispo = 0' : '' )." WHERE id = $id",$mysqli) || !recent($mysqli,4,$id,array('protection'=>$protection)) )
          exit('{"etat":"nok","message":"Le réglage d\'affichage de l\'événement n\'a pas été modifié. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');
      }
      if ( $edition != $r['edition'] )  {
        if ( !requete('agenda',"UPDATE agenda SET edition = $edition WHERE id = $id",$mysqli) )
          exit('{"etat":"nok","message":"Le réglage d\'affichage de l\'événement n\'a pas été modifié. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');
      }
      if ( $index_aff != $r['index_aff'] )  {
        if ( !requete('agenda',"UPDATE agenda SET index_aff = $index_aff WHERE id = $id",$mysqli) )
          exit('{"etat":"nok","message":"Le réglage d\'affichage de l\'événement n\'a pas été modifié. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');
      }
      exit($_SESSION['message'] = '{"etat":"ok","message":"Le réglage d\'affichage de l\'événement a été modifié.","reload":"1"}');
    }

    // Modification de la date de disponibilité
    if ( isset($_REQUEST['dispo']) )  {
      if ( $dispo = valide_date('dispo') )  {
        if ( $r['dispo'] == $dispo )
          exit('{"etat":"nok","message":"La date de disponibilité de l\'événement n\'a pas été modifiée."}');
        // Modification uniquement si dispo est dans le futur
        if ( $dispo < date('Y-m-d H:i') ) 
          exit('{"etat":"nok","message":"La date de disponibilité de l\'événement n\'a pas été modifiée car la valeur donnée est déjà passée."}');
        // Visibilité automatiquement rendue
        $requete = "dispo = '$dispo'";
        $modifrecent = array('publi'=>$dispo);
        if ( $r['protection'] == 32 )  {
          $requete .= ", protection = $protection_agenda";
          $modifrecent['protection'] = $protection_agenda;
        }
        if ( requete('agenda',"UPDATE agenda SET $requete WHERE id = $id",$mysqli) && recent($mysqli,4,$id,$modifrecent) )
          exit($_SESSION['message'] = '{"etat":"ok","message":"La date de disponibilité de l\'événement a été modifiée.","reload":"1"}');
        exit('{"etat":"nok","message":"La date de disponibilité de l\'événement n\'a pas été modifiée. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');
      }
      // Pas de dispo donnée : suppression de l'affichage différé si existant
      // et dans le futur -> affichage immédiat
      elseif ( $r['dispo'] > date('Y-m-d H:i') )  {
        if ( requete('agenda',"UPDATE agenda SET dispo = 0 WHERE id = $id",$mysqli) && recent($mysqli,4,$id,array('publi'=>0)) )
          exit($_SESSION['message'] = '{"etat":"ok","message":"L\'événement apparaît désormais pour les autres utilisateurs.","reload":"1"}');
        exit('{"etat":"nok","message":"La date de disponibilité de l\'événement n\'a pas été supprimée. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');
      }
    }
  
    // Apparition, avec la protection de base de l'agenda
    if ( isset($_REQUEST['montre']) )
      exit( requete('agenda',"UPDATE agenda SET protection = $protection_agenda, edition = 0 WHERE id = $id",$mysqli) && recent($mysqli,4,$id,array('protection'=>$protection_agenda))
          ? '{"etat":"ok","message":"L\'événement apparaît désormais pour les autres utilisateurs."}'
          : '{"etat":"nok","message":"L\'événement n\'a pas été diffusé. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');

    // Disparition
    // Supprime l'éventuel affichage différé
    if ( isset($_REQUEST['cache']) )  {
      if ( requete('agenda',"UPDATE agenda SET protection = 32, edition = $edition_agenda, dispo = 0 WHERE id = $id",$mysqli) && recent($mysqli,4,$id,array('protection'=>32)) )  {
        // Rechargement si les droits d'accès sont différents de ceux de la page (pour enlever les icônes)
        if ( ( $r['protection'] != $protection_agenda ) || ( $r['edition'] != $edition_agenda ) )
          exit($_SESSION['message'] = '{"etat":"ok","message":"L\'événement n\'apparaît plus désormais pour les autres utilisateurs mais est toujours disponible ici pour modification ou diffusion.","reload":"1"}');
        exit('{"etat":"ok","message":"L\'événement n\'apparaît plus désormais pour les autres utilisateurs mais est toujours disponible ici pour modification ou diffusion."}');
      }
      exit('{"etat":"nok","message":"L\'événement n\'a pas été caché. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');
    }

    // Suppression
    if ( isset($_REQUEST['supprime']) )  {
      if ( requete('agenda',"DELETE FROM agenda WHERE id = $id",$mysqli) && recent($mysqli,4,$id) ) 
        exit($_SESSION['message'] = '{"etat":"ok","message":"L\'événement a été supprimé.","reload":"1"}');
      exit('{"etat":"nok","message":"L\'événement n\'a pas été supprimé. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');
    }
  }
}

//////////////////////////////////////
// Ajout d'un événement de l'agenda //
//////////////////////////////////////
elseif ( ( $action == 'ajout-agenda' ) && ctype_digit($mid = $_REQUEST['matiere'] ?? '') && ctype_digit($tid = $_REQUEST['type'] ?? '') && ctype_digit($protection = $_REQUEST['protection'] ?? '') && ctype_digit($edition = $_REQUEST['edition'] ?? '')  )  {

  // Récupération des préférences globales de protection/édition de l'agenda
  // Préférences : agenda_edition, agenda_protection
  $resultat = $mysqli->query('SELECT GROUP_CONCAT(val ORDER BY nom) FROM prefs WHERE nom LIKE \'agenda%ion\'');
  list($edition_agenda, $protection_agenda) = explode(',',$resultat->fetch_row()[0]);
  $resultat->free();
  // Si fonctionnalité désactivée
  if ( $protection_agenda == 32 )
    exit('{"etat":"nok","message":"La fonctionnalité agenda a été désactivée. Vous devez la réactiver sur la page de gestion des réglages."}');

  // Vérification de l'accès : les professeurs ou les éditeurs de l'agenda
  if ( !( ( $autorisation == 5 ) || $edition_agenda && ( ($edition_agenda-1)>>($autorisation-1) & 1 ) ) )
    exit('{"etat":"nok","message":"Identifiant de page non valide"}');

  // Validation du type d'événement, récupération du nom pour les infos récentes
  $resultat = $mysqli->query("SELECT nom FROM `agenda-types` WHERE id = $tid");
  if ( !$resultat->num_rows )
    exit('{"etat":"nok","message":"Type d\'événement non valide."}');
  $type = $mysqli->real_escape_string($resultat->fetch_row()[0]);
  $resultat->free();
  // Validation de la matière si non nulle, récupération du nom pour les infos récentes
  $matiere = '';
  if ( $mid )  {
    $resultat = $mysqli->query("SELECT nom FROM matieres WHERE id = $mid");
    // Seuls les administrateurs ou les profs/éditeurs associés à la matière peuvent écrire
    // Dernière partie : cas des professeurs-colleurs
    if ( !( $resultat->num_rows && ( $_SESSION['admin'] || in_array($mid,explode(',',$_SESSION['matieres'])) || 
         ( $autorisation == 5 ) && strpos($_SESSION['matieres'],'c') && in_array("c$mid",explode(',',$_SESSION['matieres'])) && ( ($edition_agenda-1) & 4 )
                                   ) ) )
      exit('{"etat":"nok","message":"Matière non valide."}');
    $matiere = 'en '.$mysqli->real_escape_string($resultat->fetch_row()[0]);
    $resultat->free();
  }
  // Validation des dates
  if ( !( $debut = valide_date('debut') ) || ( $debut > ( $fin = valide_date('fin') ) ) )
    exit('{"etat":"nok","message":"Les dates/heures choisies ne sont pas valables."}');
  // Validation des dates : l'événement doit se trouver en partie dans
  // l'année scolaire
  $resultat = $mysqli->query("SELECT id FROM semaines WHERE debut <= '$fin' AND debut >= SUBDATE('$debut',80) LIMIT 1");
  if ( !$resultat->num_rows )
    exit('{"etat":"nok","message":"Les dates de l\'événement le placent hors de l\'année scolaire."}');
  $resultat->free();
  // Validation du texte
  $texte = $mysqli->real_escape_string($_REQUEST['texte'] ?? '');
  // Validation de la protection
  // La protection de l'événement et la protection de l'agenda sont
  // indépendantes l'une de l'autre. Un utilisateur peut voir l'événement sur
  // les événements récents sans la voir sur l'agenda. 
  // Sans matière, protection non nulle entre 1 et 15, ou 32 
  if ( !$mid && $protection ) 
    $protection = ( $protection & 15 ) ?: 32;
  // Validation de l'édition. Les deux protections (agenda et événement)
  // doivent obligatoirement inclure l'édition.
  $edition = ( $edition ) ? ($edition = ($edition-1) & (32-($protection?:1)) & (32-($protection_agenda?:1)) & 30) + ($edition>0) : 0;
  // Validation de l'affichage en page d'accueil
  $index_aff = intval(isset($_REQUEST['index_aff']));
  // Vérification de l'affichage différé et de la date de disponibilité, seulement si dans le futur et si visible
  if ( !isset($_REQUEST['affdiff']) || ( $protection == 32 ) || ( ( $dispo = valide_date('dispo') ) < date('Y-m-d H:i') ) )
    $dispo = 0;
  // Écriture
  if ( !isset($_REQUEST['recur']) )  {
    if ( requete('agenda',"INSERT INTO agenda SET matiere = $mid, type = $tid, debut = '$debut', fin = '$fin', texte = '$texte', protection = $protection, edition = $edition, index_aff = $index_aff", $mysqli) 
      && recent($mysqli,4,$mysqli->insert_id,true) )  {
      if ( $protection == 32 )
        $message = 'L\'événement a été ajouté mais reste invisible pour l\'instant.';
      elseif ( $dispo )
        $message = 'L\'événement a été ajouté mais ne sera visible que le '.date('d/m/Y',strtotime($dispo)).' à '.str_replace(':','h',substr($dispo,11)).'.';
      else
        $message = 'L\'événement a été ajouté.';
      exit($_SESSION['message'] = "{\"etat\":\"ok\",\"message\":\"$message\",\"reload\":\"1\"}");
    }
    exit('{"etat":"nok","message":"L\'événement n\'a pas été ajouté. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');
  }
  else  {
    // Récupération et vérification de la date finale de récurrence
    if ( ( $recur_fin = valide_date('recur_fin',false) ) < $debut )
      exit('{"etat":"nok","message":"La date finale de récurrence n\'est pas valable. Aucun événement n\'a été ajouté."}');
    $resultat = $mysqli->query("SELECT id FROM semaines WHERE debut >= SUBDATE('$recur_fin',80) LIMIT 1");
    if ( !$resultat->num_rows )
      exit('{"etat":"nok","message":"La date finale de récurrence n\'est pas dans l\'année scolaire. Aucun événement n\'a été ajouté."}');
    $resultat->free();
    // On n'autorise pas une création de plus de 50 événements
    $intervalle = intval($_REQUEST['recur_step']);
    if ( date('Y-m-d',strtotime("$debut + ".(50*$intervalle).' days')) < $recur_fin )
      exit('{"etat":"nok","message":"Les réglages de récurrence conduiraient à la création de plus de 50 événements, ce qui n\'est pas autorisé. Aucun événement n\'a été ajouté."}');
    $ids = array();
    while ( substr($debut,0,10) <= $recur_fin )  { 
      if ( requete('agenda',"INSERT INTO agenda SET matiere = $mid, type = $tid, debut = '$debut', fin = '$fin', texte = '$texte', protection = $protection, edition = $edition, dispo = '$dispo', index_aff = $index_aff", $mysqli) )
        $ids[] = $mysqli->insert_id;
      elseif ( $ids )  {
        recents($mysqli,4,$ids,true);
        exit($_SESSION['message'] = "{\"etat\":\"ok\",\"message\":\"$ok événements ont été ajoutés, mais une erreur est survenue avant la fin. Pensez à vérifier les événements ajoutés.\",\"reload\":\"1\"}");
      }
      else
        exit('{"etat":"nok","message":"Aucun événement n\'a été ajouté. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');
      // Coup suivant
      $debut = date('Y-m-d H:i',strtotime("$debut + $intervalle days"));
      $fin = date('Y-m-d H:i',strtotime("$fin + $intervalle days"));
    }
    recents($mysqli,4,$ids,true);
    if ( $protection == 32 )
      $message = count($ids).' événements ont été ajoutés mais restent invisibles pour l\'instant.';
    elseif ( $dispo )
      $message = count($ids).' événements ont été ajoutés mais ne seront visibles que le '.date('d/m/Y',strtotime($dispo)).' à '.str_replace(':','h',substr($dispo,11)).'.';
    else
      $message = count($ids).' événements ont été ajoutés.';
    exit($_SESSION['message'] = "{\"etat\":\"ok\",\"message\":\"$message\",\"reload\":\"1\"}");
  }
}

// Sans action
exit('{"etat":"nok","message":"Aucune action effectuée"}');
?>
