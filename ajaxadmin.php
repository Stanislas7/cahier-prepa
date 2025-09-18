<?php
// Sécurité : script obligatoirement inclus par ajax.php
if ( !defined('OK') )  exit();

// Script d'exécution des commandes ajax pour l'administration
// Nécessite d'être administrateur en connexion normale
if ( !$autorisation || !$_SESSION['admin'] || !connexionlight() )
  exit( '{"etat":"nok","message":"Aucune action effectuée"}' );
$mysqli = connectsql(true);
// Spécifications pour les manipulations de caractères sur 2 octets (accents)
mb_internal_encoding('UTF-8');

/////////////////////////
// Ajout d'une matière //
/////////////////////////
if ( $action == 'ajout-matiere' )  {
  $nom = mb_strtoupper(mb_substr($nom = trim(strip_tags($mysqli->real_escape_string($_REQUEST['nom'] ?? ''))), 0,1)) . mb_substr($nom,1);
  $cle = str_replace(' ','_',strip_tags(trim($_REQUEST['cle'] ?? '')));
  if ( !$nom || !$cle )
    exit('{"etat":"nok","message":"La matière n\'a pas été ajoutée. Le nom et la clé doivent être non vides."}');
  // Vérification que la clé n'existe pas déjà
  $resultat = $mysqli->query('SELECT cle FROM matieres');
  while ( $r = $resultat->fetch_row() )
    if ( $r[0] == $cle )
      exit("{\"etat\":\"nok\",\"message\":\"La matière <em>$nom</em> n'a pas été ajoutée. La clé donnée existe déjà. Elle doit être différente de celles des autres matières.\"}");
  $resultat->free();
  $cle = $mysqli->real_escape_string($cle);
  // Génération des valeurs de protection
  $requete = array();
  foreach ( array('progcolles','cdt','docs') as $fonction )  {
    if ( !ctype_digit($val = $_REQUEST["{$fonction}_protection"] ?? '') )
      exit("{\"etat\":\"nok\",\"message\":\"La matière <em>$nom</em> n'a pas été ajoutée. Une des protections d'accès est incorrecte.\"}");
    $requete[] = ( $val == 33 ) ? "$fonction = 2, {$fonction}_protection = 32" : "$fonction = 0, {$fonction}_protection = $val";
    if ( $fonction == 'docs' )
      $docs_protection = $val;
  }
  // Notes et transferts : 0-> possible (0 pour l'instant dans la base), 2-> désactivée
  // Valeur de protection nulle par défaut si transferts = 0 (s'adaptera au premier transfert)
  if ( !isset($_REQUEST['transferts']) || !isset($_REQUEST['notescolles']) || !isset($_REQUEST['dureecolles']) || !isset($_REQUEST['heurescolles']) )
    exit("{\"etat\":\"nok\",\"message\":\"La matière <em>$nom</em> n'a pas été ajoutée. Un des réglages sur les transferts ou les notes de colles est incorrect.\"}");
  $requete[] = ( $_REQUEST['transferts'] ) ? 'transferts = 2, transferts_protection = 32' : 'transferts = 0';
  $notes = ( $_REQUEST['notescolles'] ) ? 2 : 0;
  $dureecolles = intval($_REQUEST['dureecolles']) ?: 20;
  $heurescolles = intval( $_REQUEST['heurescolles'] == 1 );
  // Écriture (transferts_protection nul par défaut)
  if ( !requete('matieres',"INSERT INTO matieres SET nom = '$nom', cle = '$cle', ".implode(', ',$requete).", notescolles = $notes, dureecolles = $dureecolles, heurescolles = $heurescolles, ordre = (SELECT MAX(ordre)+1 FROM matieres AS m)",$mysqli) )
    exit("{\"etat\":\"nok\",\"message\":\"La matière <em>$nom</em> n'a pas été ajoutée. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');
  $id = $mysqli->insert_id;
  requete('reps',"INSERT INTO reps SET parent = 0, parents = '0', nom = '$nom', matiere = $id, protection = $docs_protection",$mysqli);
  requete('cdt-types',"INSERT INTO `cdt-types` (matiere, ordre, cle, titre, deb_fin_pour) VALUES
                       ($id, 1, 'cours', 'Cours', 1),
                       ($id, 2, 'TD', 'Séance de travaux dirigés', 1),
                       ($id, 3, 'TP', 'Séance de travaux pratiques', 1),
                       ($id, 4, 'DS', 'Devoir surveillé', 1),
                       ($id, 5, 'interros', 'Interrogation de cours', 0),
                       ($id, 6, 'distributions', 'Distribution de document', 0),
                       ($id, 7, 'DM', 'Devoir maison', 2)",$mysqli);
  requete('utilisateurs',"UPDATE utilisateurs SET matieres = CONCAT(matieres,',$id'), menumatieres = CONCAT(menumatieres,',$id') WHERE autorisation = 2",$mysqli);
  exit("{\"etat\":\"ok\",\"message\":\"La matière <em>$nom</em> a été ajoutée et automatiquement ajoutée à tous les élèves.\",\"reload\":\"2\"}");
}

//////////////////////////////////////////
// Modification d'un utilisateur unique //
//////////////////////////////////////////
elseif ( ( $action == 'utilisateur' ) && in_array($modif = $_REQUEST['modif'] ?? '',array('prefs','desactive','active','supprutilisateur','validutilisateur','renvoiinvite'),true) && ctype_digit($id = $_REQUEST['id'] ?? '') )  {

  // Vérification que l'identifiant est valide
  // Attention, les valeurs "valide", "demande" et "invitation" sont des chaines de caractères égales à '0' ou '1'.
  $resultat = $mysqli->query("SELECT nom, prenom, login, matieres, mail, (LENGTH(mdp)=40) AS valide, (LEFT(mdp,1)='*') AS demande, (LENGTH(mdp)=1) AS invitation, autorisation, autorisation%10 AS a, mailexp, mailcopie FROM utilisateurs WHERE id = $id");
  if ( !$resultat->num_rows )
    exit('{"etat":"nok","message":"Identifiant non valide"}');  
  $r = $resultat->fetch_assoc();
  $resultat->free();
  $compte = ( $r['nom'].$r['prenom'] ) ? "de <em>${r['prenom']} ${r['nom']}</em>" : "<em>${r['login']}</em>";
  // Si interface globale activée et compte non invité, il y a de fortes chances
  // que l'on doive mettre à jour la base globale. Sinon, on crée une fausse fonction
  // majutilisateurs pour éviter les erreurs.
  if ( ( $r['autorisation'] > 1 ) && $interfaceglobale )
    include("${interfaceglobale}majutilisateurs.php");
  else  {
    function majutilisateurs($id, $requete) { return true; }
  }
  
  switch ( $modif )  {

    // Modification des données du compte (venant du formulaire de utilisateurs.php)
    case 'prefs': {
      $requete = array();
      // Nom, prénom
      if ( ( $nom = mb_convert_case(strip_tags(trim($_REQUEST['nom'] ?? '')),MB_CASE_TITLE) ) && ( $nom != $r['nom'] ) )
        $requete['nom'] = $nom;
      if ( ( $prenom = mb_convert_case(strip_tags(trim($_REQUEST['prenom'] ?? '')),MB_CASE_TITLE) ) && ( $prenom != $r['prenom'] ) )
        $requete['prenom'] = $prenom;
      // Identifiant : vérification silencieuse que l'identifiant n'est pas utilisé
      if ( ( $login = mb_strtolower(str_replace(' ','_',(trim($_REQUEST['login'] ?? '')))) ) && ( $login != $r['login'] ) )  {
        $resultat = $mysqli->query('SELECT id FROM utilisateurs WHERE login = \''.$mysqli->real_escape_string($login)."' AND id != $id");
        if ( $resultat->num_rows )
          $resultat->free();
        else
          $requete['login'] = $login;
      }
      // Adresse électronique : vérification silencieuse que l'adresse n'est pas utilisée
      if ( filter_var($mail = mb_strtolower(trim($_REQUEST['mail1'] ?? '')),FILTER_VALIDATE_EMAIL) && ( $mail != $r['mail'] ) )  {
        if ( $_REQUEST['mail1'] != ( $_REQUEST['mail2'] ?? '' ) )
          exit("{\"etat\":\"nok\",\"message\":\"Les préférences $compte n'ont pas été modifiées, les deux adresses électroniques saisies ne sont pas identiques.\"}");
        $resultat = $mysqli->query('SELECT id FROM utilisateurs WHERE mail = \''.$mysqli->real_escape_string($mail)."' AND id != $id");
        if ( $resultat->num_rows )
          $resultat->free();
        else
          $requete['mail'] = $mail;
      }
      // Nom d'expéditeur et mise en copie ou non
      if ( ( $mailexp = strip_tags(trim($_REQUEST['mailexp'] ?? '')) ) && ( $mailexp != $r['mailexp'] ) )
        $requete['mailexp'] = $mailexp;
      if ( isset($_REQUEST['mailexp']) && ( ( $mailcopie = intval(isset($_REQUEST['mailcopie'])) ) !=  $r['mailcopie'] ) )
        $requete['mailcopie'] = $mailcopie;
      // Autorisation et droits d'administration
      if ( ( $r['autorisation'] > 1 ) && in_array($a = intval($_REQUEST['autorisation'] ?? 0),array(2,3,4,5)) )  {
        if ( $a != $r['a'] )  {
          // Actuellement élève : vérifier qu'il n'a pas de note de colle ni de transfert de document
          if ( $r['a'] == 2 )  {
            $resultat = $mysqli->query("SELECT id FROM ( SELECT eleve AS id FROM notescolles UNION SELECT eleve FROM transdocs ) AS t WHERE id = $id");
            if ( $resultat->num_rows )  {
              $resultat->free();
              exit("{\"etat\":\"nok\",\"message\":\"Le type du compte $compte ne peut être modifié : cet élève a des notes de colles ou des transferts de documents enregistrés.\"}");
            }
            $requete['autorisation'] = $r['a'] = $a;
          }
          // Actuellement colleur/lycée/prof : vérifier qu'il n'a pas de note de colle ni de transfert de document
          elseif ( $r['a'] > 2 )  {
            $resultat = $mysqli->query("SELECT id FROM ( SELECT colleur AS id FROM notescolles UNION SELECT utilisateur FROM transdocs ) AS t WHERE id = $id");
            if ( $resultat->num_rows )  {
              $resultat->free();
              exit("{\"etat\":\"nok\",\"message\":\"Le type du compte $compte ne peut être modifié : cet utilisateur a mis des notes de colles ou réalisé des transferts de documents.\"}");
            }
            $requete['autorisation'] = $r['a'] = $a;
            // Cas prof-colleur devenu colleur : il faut modifier la liste des matières
            if ( strpos($r['matieres'],'c') )  {
              $matieres = explode(',',str_replace('c','',$r['matieres']));
              sort($matieres);
              $requete['matieres'] = implode(',',$matieres);
            }
          }
        }
        // Droits d'administration
        if ( ( $r['a'] > 2 ) && ( ( $a = $r['a'] + 10*intval(isset($_REQUEST['admin'])) ) != $r['autorisation'] ) )
          $requete['autorisation'] = $a;
        // Modification du menu
        if ( isset($requete['autorisation']) )
          $requete['menuelements'] = '';
      }
      
      // Exécution
      if ( !$requete )
        exit('{"etat":"ok","message":"Les valeurs fournies étaient celles déjà enregistrées. Aucune modification n\'a été effectuée."}');
      $r = $requete;
      array_walk($requete, function (&$val,$champ,$mysqli) { $val = "$champ = '".$mysqli->real_escape_string($val).'\''; }, $mysqli);
      if ( !requete('utilisateurs','UPDATE utilisateurs SET '. implode(', ',$requete) ." WHERE id = $id",$mysqli) )
        exit("{\"etat\":\"nok\",\"message\":\"Les préférences $compte n'ont pas été modifiées. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');
      // Si interface globale activée, mise à jour
      majutilisateurs($id,$r);
      if ( ( $_SESSION['id'] == $id ) && isset($requete['autorisation']) )  {
        $_SESSION['autorisation'] = $a%10;
        $_SESSION['admin'] = ( $a > 10 );
      }
      exit($_SESSION['message'] = "{\"etat\":\"ok\",\"message\":\"Les préférences $compte ont été modifiées.\",\"reload\":\"1\"}");
    }

    // Désactivation
    case 'desactive': {
      $resultat = $mysqli->query("SELECT id FROM utilisateurs WHERE autorisation > 5 AND id != $id");
      if ( !$resultat->num_rows )  {
        $resultat->free();
        exit("{\"etat\":\"nok\",\"message\":\"La désactivation du compte $compte n'a pas été réalisée, car il s'agit du seul administrateur du Cahier.\"}");
      }
      if ( ( $r['valide'] == 0 ) && ( $r['demande'] == 0 ) && ( $r['invitation'] == 0 ) )
        exit($_SESSION['message'] = "{\"etat\":\"ok\",\"message\":\"Le compte $compte est déjà actuellement désactivé.\",\"reload\":\"2\"}");
      if ( ( $r['demande'] == 1 ) || ( $r['invitation'] == 1 ) )
        exit($_SESSION['message'] = "{\"etat\":\"ok\",\"message\":\"Le compte $compte n'est pas actuellement désactivable.\",\"reload\":\"2\"}");
      if ( !requete('utilisateurs',"UPDATE utilisateurs SET mdp = CONCAT('!',mdp) WHERE id = $id",$mysqli) )
        exit("{\"etat\":\"nok\",\"message\":\"La désactivation du compte $compte n'a pas été réalisée. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');
      // Si interface globale activée, mise à jour
      majutilisateurs($id,'désactivation');
      exit($_SESSION['message'] = "{\"etat\":\"ok\",\"message\":\"Compte $compte désactivé\",\"reload\":\"1\"}");
    }

    // Réactivation
    case 'active': {
      if ( $r['valide'] == 1 )
        exit($_SESSION['message'] = "{\"etat\":\"ok\",\"message\":\"Le compte $compte est déjà actuellement activé.\",\"reload\":\"2\"}");
      if ( ( $r['demande'] == 1 ) || ( $r['invitation'] == 1 ) )
        exit($_SESSION['message'] = "{\"etat\":\"ok\",\"message\":\"Le compte $compte n'est pas actuellement activable.\",\"reload\":\"2\"}");
      if ( !requete('utilisateurs',"UPDATE utilisateurs SET mdp = SUBSTR(mdp,2) WHERE id = $id",$mysqli) )
        exit("{\"etat\":\"nok\",\"message\":\"La réactivation du compte $compte n'a pas été réalisée. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');
      // Si interface globale activée, mise à jour
      majutilisateurs($id,'activation');
      exit($_SESSION['message'] = "{\"etat\":\"ok\",\"message\":\"Compte $compte réactivé\",\"reload\":\"1\"}");
    }

    // Suppression
    case 'supprutilisateur': {
      $resultat = $mysqli->query("SELECT id FROM utilisateurs WHERE autorisation > 5 AND id != $id");
      if ( !$resultat->num_rows )  {
        $resultat->free();
        exit("{\"etat\":\"nok\",\"message\":\"La suppression du compte $compte n'a pas été réalisée, car il s'agit du seul administrateur du Cahier.\"}");
      }
      if ( !requete('utilisateurs',"DELETE FROM utilisateurs WHERE id = $id",$mysqli) )
        exit("{\"etat\":\"nok\",\"message\":\"La suppression du compte $compte n'a pas été réalisée. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');
      // Recherche de l'appartenance à un groupe
      $resultat = $mysqli->query("SELECT id FROM groupes WHERE FIND_IN_SET($id,utilisateurs)");
      if ( $resultat->num_rows )  {
        requete('groupes',"UPDATE groupes SET utilisateurs = TRIM(BOTH ',' FROM REPLACE(CONCAT(',',utilisateurs,','),',$id,',',')) WHERE FIND_IN_SET($id,utilisateurs)",$mysqli);
        $resultat->free();
        $resultat = $mysqli->query('SELECT GROUP_CONCAT(id) FROM groupes WHERE utilisateurs = \'\'');
        $ids = $resultat->fetch_row()[0];
        $resultat->free();
        if ( !is_null($ids) )
          requete('groupes',"DELETE FROM groupes WHERE FIND_IN_SET(id,'$ids')",$mysqli);
      }
      // Élèves : recherche des colles et des transferts, à supprimer
      if ( $r['autorisation'] == 2 )  {
        // Colles
        $resultat = $mysqli->query("SELECT GROUP_CONCAT(heure) FROM notescolles WHERE eleve = $id");
        $ids = $resultat->fetch_row()[0];
        $resultat->free();
        if ( !is_null($ids) )  {
          requete('notescolles',"DELETE FROM notescolles WHERE eleve = $id",$mysqli);
          requete('heurescolles',"DELETE heurescolles FROM heurescolles LEFT JOIN notescolles ON heurescolles.id = notescolles.heure
                                  WHERE FIND_IN_SET(heurescolles.id,'$ids') AND notescolles.id IS NULL",$mysqli);
          requete('heurescolles',"UPDATE heurescolles SET duree = duree-(SELECT dureecolles FROM matieres WHERE matieres.id = heurescolles.matiere) 
                                  WHERE FIND_IN_SET(heurescolles.id,'$ids') AND releve = 0",$mysqli);
        }
        // Transferts 
        $resultat = $mysqli->query("SELECT transdocs.id, transfert, utilisateur, numero, ext, lien 
                                    FROM transdocs JOIN transferts ON transferts.id = transfert WHERE eleve = $id");
        if ( $resultat->num_rows )  {
          while( $r = $resultat->fetch_assoc() )  {
            requete('transdocs',"DELETE FROM transdocs WHERE id = ${r['id']}",$mysqli); 
            requete('transdocs',"UPDATE transdocs SET numero = numero-1 WHERE transfert = ${r['transfert']} AND eleve = $id AND utilisateur = ${r['utilisateur']} AND numero > ${r['numero']}",$mysqli);
            unlink("documents/${r['lien']}/${id}_${r['id']}.${r['ext']}");
          }
          $resultat->free();
        }
      }
      // Colleurs ou profs : recherche des colles et des transferts, à supprimer
      elseif ( $r['autorisation'] > 2 )  {
        // Colles
        $resultat = $mysqli->query("SELECT id FROM heurescolles WHERE colleur = $id");
        if ( $resultat->num_rows )  {
          $resultat->free();
          requete('notescolles',"DELETE FROM notescolles WHERE colleur = $id",$mysqli);
          requete('heurescolles',"DELETE FROM heurescolles WHERE colleur = $id",$mysqli);
        }
        // Transferts
        $resultat = $mysqli->query("SELECT transdocs.id, transfert, eleve, numero, ext, lien
                                    FROM transdocs JOIN transferts ON transferts.id = transfert WHERE utilisateur = $id");
        if ( $resultat->num_rows )  {
          while( $r = $resultat->fetch_assoc() )  {
            requete('transdocs',"DELETE FROM transdocs WHERE id = ${r['id']}",$mysqli); 
            requete('transdocs',"UPDATE transdocs SET numero = numero-1 WHERE transfert = ${r['transfert']} AND eleve = ${r['eleve']} AND utilisateur = $id AND numero > ${r['numero']}",$mysqli);
            unlink("documents/${r['lien']}/${id}_${r['id']}.${r['ext']}");
          }
          $resultat->free();
        }
      }
      // Si interface globale activée, mise à jour
      majutilisateurs($id,'suppression');
      exit($_SESSION['message'] = "{\"etat\":\"ok\",\"message\":\"Compte $compte supprimé\",\"reload\":\"1\"}");
    }

    // Validation d'une demande
    case 'validutilisateur': {
      if ( $r['demande'] == 0 )
        exit($_SESSION['message'] = "{\"etat\":\"ok\",\"message\":\"La demande $compte a déjà été validée.\",\"reload\":\"2\"}");
      if ( !requete('utilisateurs',"UPDATE utilisateurs SET mdp = SUBSTR(mdp,2) WHERE id = $id",$mysqli) )
        exit("{\"etat\":\"nok\",\"message\":\"La validation du compte $compte n'a pas été réalisée. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');
      mail($r['mail'],'=?UTF-8?B?'.base64_encode('[Cahier de Prépa] Compte validé').'?=',
"Bonjour

Vous avez rempli une demande de création de compte sur le Cahier de Prépa <https://$domaine$chemin>, correspondant à l'identifiant ${r['login']}.

Cette demande vient de recevoir une réponse favorable de la part de l'équipe pédagogique en charge du site. Vous pouvez donc désormais vous connecter avec votre identifiant et votre mot de passe.

Bonne navigation sur Cahier de Prépa.

Cordialement,
-- 
Cahier de Prépa
",'From: =?UTF-8?B?'.base64_encode('Cahier de Prépa')."?= <$mailadmin>\r\nList-Unsubscribe: <mailto:contact".strstr($mailadmin,'@')."?subject=unsubscribe>\r\nContent-type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit","-f$mailadmin");
      // Si interface globale activée, mise à jour
      majutilisateurs($id,'activation');
      exit($_SESSION['message'] = "{\"etat\":\"ok\",\"message\":\"Demande de compte $compte accordée. L'élève a été prévenu par courriel.\",\"reload\":\"1\"}");
    }
    
    // Renvoi de l'invitation
    case 'renvoiinvite': {
      if ( $r['invitation'] == 0 )
        exit($_SESSION['message'] = "{\"etat\":\"ok\",\"message\":\"L'invitation $compte a déjà été répondue.\",\"reload\":\"2\"}");
      $lien = "https://$domaine${chemin}gestioncompte?invitation&mail=".str_replace('@','__',$r['mail']).'&p='.sha1($chemin.$mdp.$r['mail']);
      mail($r['mail'],'=?UTF-8?B?'.base64_encode('[Cahier de Prépa] Invitation').'?=',
"Bonjour

L'équipe pédagogique en charge du Cahier de Prépa <https://$domaine$chemin> vous invite à les rejoindre.

S'il s'agit d'une erreur, merci d'ignorer simplement ce courriel.

Sinon, veuillez cliquer ci-dessous pour vous rendre à la page qui vous permettra d'entrer un mot de passe :
   $lien

Si ce lien ne s'ouvre pas correctement, il a peut-être été coupé lors du clic : dans ce cas, essayez à nouveau en copiant-collant le lien.

Bonne navigation sur Cahier de Prépa.

Cordialement,
-- 
Cahier de Prépa
",'From: =?UTF-8?B?'.base64_encode('Cahier de Prépa')."?= <$mailadmin>\r\nList-Unsubscribe: <mailto:contact".strstr($mailadmin,'@')."?subject=unsubscribe>\r\nContent-type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit","-f$mailadmin");
      // Rechargement inutile mais quasi invisible, simplifiant le code js
      exit($_SESSION['message'] = "{\"etat\":\"ok\",\"message\":\"L'invitation $compte a été renvoyée.\",\"reload\":\"1\"}");
    }
  }
}

//////////////////////////////////////////
// Modification multiple d'utilisateurs // 
//////////////////////////////////////////
elseif ( ( $action == 'utilisateurs' ) && in_array($modif = $_REQUEST['modif'] ?? '',array('desactive','active','supprutilisateur','validutilisateur','renvoiinvite'),true) && ($ids = implode(',',array_filter(explode(',',$_REQUEST['ids'] ?? ''),'ctype_digit'))) )  {

  // Vérification que les identifiants sont valides
  // Attention, les valeurs "valide", "demande" et "invitation" sont des chaines de caractères égales à '0' ou '1'.
  $resultat = $mysqli->query("SELECT id, nom, prenom, login, mail, (LENGTH(mdp)=40) AS valide, (LEFT(mdp,1)='*') AS demande, (LENGTH(mdp)=1) AS invitation, autorisation%10 AS autorisation, mailexp, mailcopie FROM utilisateurs WHERE FIND_IN_SET(id,'$ids') ORDER BY nom,prenom,login");
  if ( !$resultat->num_rows )
    exit('{"etat":"nok","message":"Identifiants non valides"}');
  $message = array('ok'=>'','nok'=>'');
  // Si interface globale activée et compte non invité, il y a de fortes chances
  // que l'on doive mettre à jour la base globale. Sinon, on crée une fausse fonction
  // majutilisateurs pour éviter les erreurs.
  if ( $interfaceglobale )
    include("${interfaceglobale}majutilisateurs.php");
  else  {
    function majutilisateurs($id, $requete) { return true; }
  }
  
  while ( $r = $resultat->fetch_assoc() )  {
    $id = $r['id'];
    $compte = ( $r['nom'].$r['prenom'] ) ? "de <em>${r['prenom']} ${r['nom']}</em>" : "<em>${r['login']}</em>";
    $action = false; // Pour la mise à jour globale
    switch ( $modif )  {

      // Désactivation
      case 'desactive': {
        if ( $id == $_SESSION['id'] ) 
          $message['nok'] .= "<strong>Vous avez essayé de désactiver votre compte pendant une désactivation multiple. Ceci n'est pas autorisé pour éviter les erreurs.</strong><br>";
        elseif ( ( $r['demande'] == 1 ) || ( $r['invitation'] == 1 ) || ( $r['valide'] == 0 ) )
          $message['nok'] .= "Le compte $compte n'a pas été désactivé, car il l'est déjà ou ne peut pas l'être.<br>";
        elseif ( requete('utilisateurs',"UPDATE utilisateurs SET mdp = CONCAT('!',mdp) WHERE id = $id",$mysqli) )  {
          $action = 'désactivation';
          $message['ok'] .= "Le compte $compte a été désactivé.<br>";
        }
        else
          $message['nok'] .= "Le compte $compte n'a pas été désactivé. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»<br>';
        break;
      }
      
      // Réactivation
      case 'active': {
        if ( ( $r['demande'] == 1 ) || ( $r['invitation'] == 1 ) || ( $r['valide'] == 1 ) )
          $message['nok'] .= "Le compte $compte n'a pas été activé, car il l'est déjà ou ne peut pas l'être.<br>";
        elseif ( requete('utilisateurs',"UPDATE utilisateurs SET mdp = SUBSTR(mdp,2) WHERE id = $id",$mysqli) )  {
          $action = 'activation';
          $message['ok'] .= "Le compte $compte a été réactivé.<br>";
        }
        else
          $message['nok'] .= "Le compte $compte n'a pas été réactivé. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»<br>';
        break;
      }
    
      // Suppression
      case 'supprutilisateur': {
        if ( $id == $_SESSION['id'] )  {
          $message['nok'] .= "<strong>Vous avez essayé de supprimer votre compte pendant une suppression multiple. Ceci n'est pas autorisé pour éviter les erreurs.</strong><br>";
          break;
        }
        if ( !requete('utilisateurs',"DELETE FROM utilisateurs WHERE id = $id",$mysqli) )  {
          $message['nok'] .= "Le compte $compte n'a pas été supprimé. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»<br>';
          break;
        }
        $action = 'suppression';
        $message['ok'] .= "Le compte $compte a été supprimé.<br>";
        // Recherche de l'appartenance à un groupe
        $resultat2 = $mysqli->query("SELECT id FROM groupes WHERE FIND_IN_SET($id,utilisateurs)");
        if ( $resultat2->num_rows )  {
          requete('groupes',"UPDATE groupes SET utilisateurs = TRIM(BOTH ',' FROM REPLACE(CONCAT(',',utilisateurs,','),',$id,',',')) WHERE FIND_IN_SET($id,utilisateurs)",$mysqli);
          $resultat2->free();
        }
        // Élèves : recherche des colles et des transferts, à supprimer
        if ( $r['autorisation'] == 2 )  {
          // Colles
          $resultat2 = $mysqli->query("SELECT GROUP_CONCAT(heure) FROM notescolles WHERE eleve = $id");
          $ids = $resultat2->fetch_row()[0];
          $resultat2->free();
          if ( !is_null($ids) )  {
            requete('notescolles',"DELETE FROM notescolles WHERE eleve = $id",$mysqli);
            requete('heurescolles',"DELETE heurescolles FROM heurescolles LEFT JOIN notescolles ON heurescolles.id = notescolles.heure
                                    WHERE FIND_IN_SET(heurescolles.id,'$ids') AND notescolles.id IS NULL",$mysqli);
            requete('heurescolles',"UPDATE heurescolles SET duree = duree-(SELECT dureecolles FROM matieres WHERE matieres.id = heurescolles.matiere) 
                                    WHERE FIND_IN_SET(heurescolles.id,'$ids') AND releve = 0",$mysqli);
          }
          // Transferts 
          $resultat2 = $mysqli->query("SELECT transdocs.id, transfert, utilisateur, numero, ext, lien 
                                      FROM transdocs JOIN transferts ON transferts.id = transfert WHERE eleve = $id");
          if ( $resultat2->num_rows )  {
            while( $s = $resultat2->fetch_assoc() )  {
              requete('transdocs',"DELETE FROM transdocs WHERE id = ${s['id']}",$mysqli); 
              requete('transdocs',"UPDATE transdocs SET numero = numero-1 WHERE transfert = ${s['transfert']} AND eleve = $id AND utilisateur = ${s['utilisateur']} AND numero > ${s['numero']}",$mysqli);
              unlink("documents/${s['lien']}/${id}_${s['id']}.${s['ext']}");
            }
            $resultat2->free();
          }
        }
        // Colleurs ou profs : recherche des colles et des transferts, à supprimer
        elseif ( $r['autorisation'] > 2 )  {
          // Colles
          $resultat2 = $mysqli->query("SELECT id FROM heurescolles WHERE colleur = $id");
          if ( $resultat2->num_rows )  {
            $resultat2->free();
            requete('notescolles',"DELETE FROM notescolles WHERE colleur = $id",$mysqli);
            requete('heurescolles',"DELETE FROM heurescolles WHERE colleur = $id",$mysqli);
          }
          // Transferts
          $resultat2 = $mysqli->query("SELECT transdocs.id, transfert, eleve, numero, ext, lien
                                      FROM transdocs JOIN transferts ON transferts.id = transfert WHERE utilisateur = $id");
          if ( $resultat2->num_rows )  {
            while( $s = $resultat2->fetch_assoc() )  {
              requete('transdocs',"DELETE FROM transdocs WHERE id = ${s['id']}",$mysqli); 
              requete('transdocs',"UPDATE transdocs SET numero = numero-1 WHERE transfert = ${s['transfert']} AND eleve = ${s['eleve']} AND utilisateur = $id AND numero > ${s['numero']}",$mysqli);
              unlink("documents/${s['lien']}/${id}_${s['id']}.${s['ext']}");
            }
            $resultat2->free();
          }
        }
        break;
      }

      // Validation d'une demande
      case 'validutilisateur': {
        if ( $r['demande'] == 0 )
          $message['nok'] .= "La demande $compte a déjà été validée.<br>";
        elseif ( requete('utilisateurs',"UPDATE utilisateurs SET mdp = SUBSTR(mdp,2) WHERE id = $id",$mysqli) )  {
          $action = 'activation';
          $message['ok'] .= "La demande de compte $compte a été accordée. L'élève a été prévenu par courriel.<br>";
          // Envoi de courriel de confirmation
          mail($r['mail'],'=?UTF-8?B?'.base64_encode('[Cahier de Prépa] Compte validé').'?=',
"Bonjour

Vous avez rempli une demande de création de compte sur le Cahier de Prépa <https://$domaine$chemin>, correspondant à l'identifiant ${r['login']}.

Cette demande vient de recevoir une réponse favorable de la part de l'équipe pédagogique en charge du site. Vous pouvez donc désormais vous connecter avec votre identifiant et votre mot de passe.

Bonne navigation sur Cahier de Prépa.

Cordialement,
-- 
Cahier de Prépa
",'From: =?UTF-8?B?'.base64_encode('Cahier de Prépa')."?= <$mailadmin>\r\nList-Unsubscribe: <mailto:contact".strstr($mailadmin,'@')."?subject=unsubscribe>\r\nContent-type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit","-f$mailadmin");
        }
        else
          $message['nok'] .= "La validation du compte $compte n'a pas été réalisée. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'».<br>';
        break;
      }
    
      // Renvoi de l'invitation
      case 'renvoiinvite': {
        if ( $r['invitation'] == 0 )
          $message['nok'] .= "L'invitation $compte a déjà été répondue.<br>";
        else  {
          $message['ok'] .= "L'invitation $compte a été renvoyée.<br>";
          $lien = "https://$domaine${chemin}gestioncompte?invitation&mail=".str_replace('@','__',$r['mail']).'&p='.sha1($chemin.$mdp.$r['mail']);
          mail($r['mail'],'=?UTF-8?B?'.base64_encode('[Cahier de Prépa] Invitation').'?=',
"Bonjour

L'équipe pédagogique en charge du Cahier de Prépa <https://$domaine$chemin> vous invite à les rejoindre.

S'il s'agit d'une erreur, merci d'ignorer simplement ce courriel.

Sinon, veuillez cliquer ci-dessous pour vous rendre à la page qui vous permettra d'entrer un mot de passe :
   $lien

Si ce lien ne s'ouvre pas correctement, il a peut-être été coupé lors du clic : dans ce cas, essayez à nouveau en copiant-collant le lien.

Bonne navigation sur Cahier de Prépa.

Cordialement,
-- 
Cahier de Prépa
",'From: =?UTF-8?B?'.base64_encode('Cahier de Prépa')."?= <$mailadmin>\r\nList-Unsubscribe: <mailto:contact".strstr($mailadmin,'@')."?subject=unsubscribe>\r\nContent-type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit","-f$mailadmin");
        }
      }
      
    }
    // Si interface globale activée, mise à jour -- seulement pour les comptes non "invité"
    if ( $action && ( $r['autorisation'] > 1 ) )
      majutilisateurs($id,$action);
  }
  $resultat->free();
  // Nettoyage des groupes et des heures de colles
  if ( $modif == 'supprutilisateur' )  {
    // Groupes
    $resultat = $mysqli->query('SELECT GROUP_CONCAT(id) FROM groupes WHERE utilisateurs = \'\'');
    $ids = $resultat->fetch_row()[0];
    $resultat->free();
    if ( !is_null($ids) )
      requete('groupes',"DELETE FROM groupes WHERE FIND_IN_SET(id,'$ids')",$mysqli);
    // Heures de colles
    $resultat = $mysqli->query('SELECT GROUP_CONCAT(heure) FROM heurescolles LEFT JOIN notescolles ON heurescolles.id=notescolles.heure WHERE notescolles.id IS NULL');
    $ids = $resultat->fetch_row()[0];
    $resultat->free();
    if ( !is_null($ids) )
      requete('heurescolles',"DELETE FROM heurescolles WHERE FIND_IN_SET(id,'$ids')",$mysqli);
  }
  if ( $message['ok'] )
    exit($_SESSION['message'] = "{\"etat\":\"ok\",\"message\":\"${message['ok']}${message['nok']}\",\"reload\":\"1\"}");
  exit("{\"etat\":\"nok\",\"message\":\"${message['nok']}\"}");
}

////////////////////////////////////
// Ajout de nouveaux utilisateurs //
////////////////////////////////////
elseif ( ( $action == 'ajout-utilisateurs' ) && in_array($autorisation = intval($_REQUEST['autorisation'] ?? 0),array(1,2,3,4,5)) && isset($_REQUEST['saisie']) )  {
  
  // Vérification des matières -- on ne garde que les identifiants existants,
  // et on prend silencieusement par défaut l'ensemble des matières
  if ( !count($matieres = array_filter($_REQUEST['matieres'] ?? array(),'ctype_digit')) )
    exit('{"etat":"nok","message":"Choix de matières non valide"}');  
  if ( $matieres[0] )  {
    $resultat = $mysqli->query('SELECT GROUP_CONCAT(id ORDER BY id) AS matieres FROM matieres');
    $r = $resultat->fetch_row();
    $resultat->free();
    $matieres = '0,'.implode(',', array_intersect($_REQUEST['matieres'],explode(',',$r[0])) ?: explode(',',$r[0]) );
  }
  else
    $matieres = '0';

  // Cas des comptes avec droits d'administrations
  if ( ( $autorisation > 2 ) && isset($_REQUEST['admin']) )
    $autorisation += 10;

  // Récupération des lignes
  $utilisateurs = explode("\n",$_REQUEST['listeutilisateurs'] ?? '');
  
  // Compteurs : $n nb de comptes ajoutés; $i compteur de ligne traitée
  $n = $i = 0;
  $message = '';
  // Comptes invités : login,mdp
  if ( $autorisation == 1 )
    foreach ( $utilisateurs as $utilisateur)  {
      if ( !trim($utilisateur) )
        continue;
      $u = array_map('trim',explode(',',$utilisateur));
      $i = $i+1;
      if ( ( count($u) != 2 ) || !$u[0] || !$u[1] )
        $message .= "<br>Ligne $i : mauvais paramètres";
      elseif ( ( $resultat = $mysqli->query('SELECT id FROM utilisateurs WHERE login = \''.($login = $mysqli->real_escape_string(mb_strtolower(str_replace(' ','_',$u[0])))).'\'') ) && $resultat->num_rows ) 
        $message .= "<br>Ligne $i : identifiant <strong>$login</strong> déjà existant";
      elseif ( requete('utilisateurs',"INSERT INTO utilisateurs SET login = '$login', mdp = '".sha1($mdp.$u[1])."', autorisation = 1, matieres = '$matieres', menumatieres = '$matieres', timeout=900",$mysqli) )  {
        $message .= "<br>Ligne $i : ok (identifiant <strong>$login</strong>)";
        $n = $n+1;
      }
      else
        $message .= "<br>Ligne $i : erreur MySQL n°".$mysqli->errno.' «'.$mysqli->error.'»';
    }
  // Autres comptes : nom,prenom,mail ou nom,prenom,mdp
  else  {
    // Si interface globale activée, récupération de la fonction de mise à jour
    if ( $interfaceglobale )
      include_once("${interfaceglobale}majutilisateurs.php");
    else  {
      function majutilisateurs($id,$requete)  { return true; }
    }
    // Si $_REQUEST['ordre'] vaut 1, c'est "nom,prénom" ; si 2, "prénom,nom" 
    $idnom = intval( isset($_REQUEST['ordre']) && ( $_REQUEST['ordre'] == 2 ) );
    foreach ( $utilisateurs as $utilisateur)  {
      if ( !trim($utilisateur) )
        continue;
      $u = array_map('trim',explode(',',$utilisateur));
      $i = $i+1;
      // Nettoyage des données envoyées
      if ( ( count($u) != 3 ) || !($u[0].$u[1]) || !$u[2] )
        $message .= "<br>Ligne $i : mauvais paramètres";
      else  {
        $nom = mb_convert_case(strip_tags($mysqli->real_escape_string($u[$idnom])),MB_CASE_TITLE);
        $prenom = mb_convert_case(strip_tags($mysqli->real_escape_string($u[1-$idnom])),MB_CASE_TITLE);
        $login = mb_strtolower(mb_substr($prenom,0,1).str_replace(' ','_',$nom));
        if ( ( $resultat = $mysqli->query("SELECT id FROM utilisateurs WHERE login = '$login'") ) && $resultat->num_rows )  {
          $resultat->free();
          $login = mb_strtolower(mb_substr($prenom,0,2).str_replace(' ','_',$nom));
          if ( ( $resultat = $mysqli->query("SELECT id FROM utilisateurs WHERE login = '$login'") ) && $resultat->num_rows )  {
            $resultat->free();
            $message .= "<br>Ligne $i : identifiant <strong>$login</strong> déjà existant";
            continue;
          }
        }
        // Si nom,prenom,mdp
        if ( $_REQUEST['saisie'] == 2 )  {
          if ( requete('utilisateurs',"INSERT INTO utilisateurs SET login = '$login', prenom = '$prenom', nom = '$nom', mdp = '".sha1($mdp.$u[2])."', autorisation = $autorisation, matieres = '$matieres', menumatieres = '$matieres', timeout = 3600",$mysqli) )  {
            $message .= "<br>Ligne $i : ok (<strong>$prenom $nom</strong>, identifiant $login)";
            $n = $n+1;
            majutilisateurs($mysqli->insert_id,"INSERT INTO utilisateurs SET login = '$login', prenom = '$prenom', nom = '$nom', mail = '', mdp = '".sha1($mdp.$u[2])."', autorisation = ".($autorisation%10) );
          }
          else
            $message .= "<br>Ligne $i : erreur MySQL n°".$mysqli->errno.' «'.$mysqli->error.'»';
        }
        // Si nom,prenom,mail
        else  {
          // Vérification de l'adresse mail (écriture et absence dans la base)
          if ( !$mailsql = $mysqli->real_escape_string(filter_var($mail = mb_strtolower($u[2]),FILTER_VALIDATE_EMAIL)) )
            $message .= "<br>Ligne $i : adresse électronique non valide (<strong>$prenom $nom</strong>)";
          elseif ( ( $resultat = $mysqli->query("SELECT id FROM utilisateurs WHERE mail = '$mail'") ) && $resultat->num_rows )  {
            $resultat->free();
            $message .= "<br>Ligne $i : adresse électronique déjà existante (<strong>$prenom $nom</strong>)";
          }
          elseif ( requete('utilisateurs',"INSERT INTO utilisateurs SET login = '$login', prenom = '$prenom', nom = '$nom', mail = '$mailsql', mdp = '?', autorisation = $autorisation, matieres = '$matieres', menumatieres = '$matieres', timeout = 3600, mailexp = '$prenom $nom'",$mysqli) )  {
            $message .= "<br>Ligne $i : ok (<strong>$prenom $nom</strong>, identifiant $login)";
            $n = $n+1;
            majutilisateurs($mysqli->insert_id,"INSERT INTO utilisateurs SET login = '$login', prenom = '$prenom', nom = '$nom', mail = '$mailsql', mdp = '?', autorisation = ".($autorisation%10) );
            // Récupération de l'adresse électronique du professeur connecté
            $lien = "https://$domaine${chemin}gestioncompte?invitation&mail=".str_replace('@','__',$mail).'&p='.sha1($chemin.$mdp.$mail);
            mail($mail,'=?UTF-8?B?'.base64_encode('[Cahier de Prépa] Invitation').'?=',
"Bonjour

L'équipe pédagogique en charge du Cahier de Prépa <https://$domaine$chemin> vous invite à les rejoindre.

S'il s'agit d'une erreur, merci d'ignorer simplement ce courriel.

Sinon, veuillez cliquer ci-dessous pour vous rendre à la page qui vous permettra d'entrer un mot de passe :
   $lien

Si ce lien ne s'ouvre pas correctement, il a peut-être été coupé lors du clic : dans ce cas, essayez à nouveau en copiant-collant le lien.

Bonne navigation sur Cahier de Prépa.

Cordialement,
-- 
Cahier de Prépa
",'From: =?UTF-8?B?'.base64_encode('Cahier de Prépa')."?= <$mailadmin>\r\nList-Unsubscribe: <mailto:contact".strstr($mailadmin,'@')."?subject=unsubscribe>\r\nContent-type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit","-f$mailadmin");
          }
          else
            $message .= "<br>Ligne $i : erreur MySQL n°".$mysqli->errno.' «'.$mysqli->error.'»';
        }
      }
    }
  }
  // Fabrication du message
  $nouveaucompte = ( $n > 1 ) ? 'nouveaux comptes' : 'nouveau compte';
  if ( $e = $i-$n )
    exit("{\"etat\":\"nok\",\"message\":\"<strong>$n $nouveaucompte et $e erreur".($e>1?'s':'').'</strong>'.str_replace('"','\"',stripslashes($message)).'"}');
  exit($_SESSION['message'] = "{\"etat\":\"ok\",\"message\":\"<strong>$n $nouveaucompte</strong>".str_replace('"','\"',stripslashes($message)).'","reload":"2"}');
}

///////////////////////////////////////////////////////////////
// Modification d'une association utilisateur-matière unique //
///////////////////////////////////////////////////////////////
elseif ( ( $action == 'utilisateur-matiere' ) && ( ctype_digit($id = $_REQUEST['id'] ?? '') || ( $id[0] == 'c' ) ) && ctype_digit($mid = $_REQUEST['matiere'] ?? '') && isset($_REQUEST['val']) )  {

  // Vérification que l'identifiant de la matière est valide
  $resultat = $mysqli->query("SELECT nom FROM matieres WHERE id = $mid");
  if ( !$resultat->num_rows )
    exit('{"etat":"nok","message":"Identifiant de matière non valide"}');
  $r = $resultat->fetch_row();
  $resultat->free();
  $matiere = $r[0];
  
  // Vérification que l'identifiant de l'utilisateur est valide
  // $profcolleur = true s'il s'agit d'un prof géré dans la catégorie "colleur"
  if ( $profcolleur = ( $id[0] == 'c' ) )
    $id = intval(substr($id,1));
  $resultat = $mysqli->query("SELECT IF(nom>'',CONCAT(prenom,' ',nom),login) AS nom, matieres, menumatieres, autorisation FROM utilisateurs WHERE id = $id");
  if ( !$resultat->num_rows )
    exit('{"etat":"nok","message":"Identifiant d\'utilisateur non valide"}');
  $r = $resultat->fetch_assoc();
  $resultat->free();
  $matieres = explode(',',$r['matieres']);

  /// Association
  if ( intval( $_REQUEST['val'] > 0 ) )  {
    if ( in_array($mid, $matieres) || in_array("c$mid", $matieres) )
      exit("{\"etat\":\"nok\",\"message\":\"La matière <em>$matiere</em> est déjà associée à <em>${r['nom']}</em>. Aucune modification n'a été effectuée.\"}");
    $matieres[] = ( $profcolleur ? "c$mid" : $mid );
    // Mise en l'ordre pour faciliter le nommage des flux RSS
    sort($matieres);
    $requete = 'matieres = \''. implode(',',$matieres) .'\''. ( in_array($mid, explode(',',$r['menumatieres'])) ? '' : ", menumatieres = '${r['menumatieres']},$mid'" );
    if ( requete('utilisateurs',"UPDATE utilisateurs SET $requete, menuelements = '' WHERE id = $id",$mysqli) )  {
      // Mise à jour de $_SESSION['matieres'] si besoin
      if ( $id == $_SESSION['id'] ) 
        $_SESSION['matieres'] = implode(',',$matieres);
      exit("{\"etat\":\"ok\",\"message\":\"La matière <em>$matiere</em> à été ajoutée à la liste des matières associées à <em>${r['nom']}</em>.\"}");
    }
    exit("{\"etat\":\"nok\",\"message\":\"L'association de <em>${r['nom']}</em> à la matière <em>$matiere</em> n'a pas été modifiée. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');
  }

  // Désassociation
  else  {
    // Vérification qu'il n'y a pas de colles/transferts si suppression de l'association -- normalement non, puisque ce n'est pas autorisé depuis la page...
    if ( $r['autorisation'] > 1 )  {
      if ( $r['autorisation'] == 2 )
        $resultat = $mysqli->query("SELECT eleve, matiere FROM transferts JOIN transdocs ON transdocs.transfert = transferts.id WHERE eleve = $id AND matiere = $mid
                                      UNION SELECT eleve, matiere FROM notescolles WHERE eleve = $id AND matiere = $mid");
      else 
        $resultat = $mysqli->query("SELECT utilisateur, matiere FROM transferts JOIN transdocs ON transdocs.transfert = transferts.id WHERE utilisateur = $id AND matiere = $mid
                                      UNION SELECT colleur, matiere FROM heurescolles WHERE colleur = $id AND matiere = $mid");
      if ( $resultat->num_rows )  {
        $resultat->free();
        exit("{\"etat\":\"nok\",\"message\":\"Il est impossible de retirer la matière <em>$matiere</em> à <em>${r['nom']}</em>, car des notes de colles ou des transferts de documents sont concernés. Aucune modification n'a été effectuée.\"}");
      }
    }
    if ( !in_array($mid, $matieres) && !in_array("c$mid", $matieres) )
      exit("{\"etat\":\"nok\",\"message\":\"La matière <em>$matiere</em> n'est pas associée à <em>${r['nom']}</em>. Aucune modification n'a été effectuée.\"}");
    $matieres = implode(',',array_diff($matieres,array($mid,"c$mid")));
    if ( requete('utilisateurs',"UPDATE utilisateurs SET matieres = '$matieres', menumatieres = '". implode(',',array_diff(explode(',',$r['menumatieres']),array($mid))) ."', menuelements = '' WHERE id = $id",$mysqli) )  {
      // Mise à jour de $_SESSION['matieres'] si besoin
      if ( $id == $_SESSION['id'] ) 
        $_SESSION['matieres'] = "$matieres";
      exit("{\"etat\":\"ok\",\"message\":\"La matière <em>$matiere</em> à été retirée à la liste des matières associées à <em>${r['nom']}</em>.\"}");
    }
    exit("{\"etat\":\"nok\",\"message\":\"L'association de </em>${r['nom']}</em> à la matière $matiere n'a pas été modifiée. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');
  }
}

////////////////////////////////////////////////////////////////
// Modification multiple d'associations utilisateurs-matières //
////////////////////////////////////////////////////////////////
elseif ( ( $action == 'utilisateurs-matieres' ) && ($ids = implode(',',array_filter(explode(',',$_REQUEST['ids'] ?? ''),function($id) { return ctype_digit($id) || $id && ( $id[0] == 'c' ) && ( ctype_digit(substr($id,1))); }))) && ctype_digit($mid = $_REQUEST['matiere'] ?? '') && isset($_REQUEST['val']) )  {
  
  // Vérification que l'identifiant de la matière est valide
  $resultat = $mysqli->query("SELECT nom FROM matieres WHERE id = $mid");
  if ( !$resultat->num_rows )
    exit('{"etat":"nok","message":"Identifiant de matière non valide"}');
  $r = $resultat->fetch_row();
  $resultat->free();
  $matiere = $r[0];
  
  // Vérification que les identifiants d'utilisateur sont valides
  $resultat = $mysqli->query('SELECT id, IF(nom>\'\',CONCAT(prenom,\' \',nom),login) AS nom, matieres, menumatieres, autorisation FROM utilisateurs WHERE FIND_IN_SET(id,\''. str_replace('c','',$ids) .'\')');
  $ids = explode(',',$ids);
  if ( $resultat->num_rows != count($ids) )
    exit('{"etat":"nok","message":"Identifiants d\'utilisateur non valides"}');  
  $message = array('ok'=>'','nok'=>'');

  /// Association
  if ( intval( $_REQUEST['val'] > 0 ) )  {
    while ( $r = $resultat->fetch_assoc() )  {
      $matieres = explode(',',$r['matieres']);
      $id = $r['id'];
      if ( in_array($mid, $matieres) || in_array("c$mid", $matieres) )
        $message['nok'] .= "La matière <em>$matiere</em> est déjà associée à <em>${r['nom']}</em>.<br>";
      else  {
        $matieres[] = ( in_array("c$id",$ids) ? "c$mid" : $mid );
        // Mise en l'ordre pour faciliter le nommage des flux RSS
        sort($matieres);
        $requete = 'matieres = \''. implode(',',$matieres) .'\''. ( in_array($mid, explode(',',$r['menumatieres'])) ? '' : ", menumatieres = '${r['menumatieres']},$mid'" );
        if ( requete('utilisateurs',"UPDATE utilisateurs SET $requete, menuelements = '' WHERE id = $id",$mysqli) )  {
          // Mise à jour de $_SESSION['matieres'] si besoin
          if ( $id == $_SESSION['id'] ) 
            $_SESSION['matieres'] = implode(',',$matieres);
          $message['ok'] .= "La matière <em>$matiere</em> à été ajoutée à la liste des matières associées à <em>${r['nom']}</em>.<br>";
        }
        else
          $message['nok'] .= "L'association de <em>${r['nom']}</em> à la matière <em>$matiere</em> n'a pas été modifiée. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»<br>';
      }
    }
  }
    
  // Désassociation
  else  {
    while ( $r = $resultat->fetch_assoc() )  {
      $matieres = explode(',',$r['matieres']);
      $id = $r['id'];
      // Vérification qu'il n'y a pas de colles/transferts si suppression de l'association
      if ( $r['autorisation'] > 1 )  {
        if ( $r['autorisation'] == 2 )
          $resultat2 = $mysqli->query("SELECT eleve, matiere FROM transferts JOIN transdocs ON transdocs.transfert = transferts.id WHERE eleve = $id AND matiere = $mid
                                         UNION SELECT eleve, matiere FROM notescolles WHERE eleve = $id AND matiere = $mid");
        else 
          $resultat2 = $mysqli->query("SELECT utilisateur, matiere FROM transferts JOIN transdocs ON transdocs.transfert = transferts.id WHERE utilisateur = $id AND matiere = $mid
                                         UNION SELECT colleur, matiere FROM heurescolles WHERE colleur = $id AND matiere = $mid");
        if ( $resultat2->num_rows )  {
          $resultat2->free();
          $message['nok'] .= "Il est impossible de retirer la matière <em>$matiere</em> à <em>${r['nom']}</em>, car des notes de colles ou des transferts de documents sont concernés.<br>";
          continue;
        }
      }
      if ( !in_array($mid, $matieres) && !in_array("c$mid", $matieres) )
        $message['nok'] .= "La matière <em>$matiere</em> n'est pas associée à <em>${r['nom']}</em>.<br>";
      else  {
        $matieres = implode(',',array_diff($matieres,array($mid,"c$mid")));
        if ( requete('utilisateurs',"UPDATE utilisateurs SET matieres = '$matieres', menumatieres = '". implode(',',array_diff(explode(',',$r['menumatieres']),array($mid))) ."', menuelements = '' WHERE id = $id",$mysqli) )  {
          // Mise à jour de $_SESSION['matieres'] si besoin
          if ( $id == $_SESSION['id'] ) 
            $_SESSION['matieres'] = "$matieres";
          $message['ok'] .= "La matière <em>$matiere</em> à été retirée à la liste des matières associées à <em>${r['nom']}</em>.<br>";
        }
        else
          $message['nok'] .= "L'association de </em>${r['nom']}</em> à la matière $matiere n'a pas été modifiée. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»<br>';
      }
    }
  }
  // Résultat final
  $resultat->free();
  if ( $message['ok'] )
    exit($_SESSION['message'] = "{\"etat\":\"ok\",\"message\":\"${message['ok']}${message['nok']}\",\"reload\":\"1\"}");
  exit("{\"etat\":\"nok\",\"message\":\"${message['nok']}\"}");
}

/////////////////////////////////////////////
// Modification des groupes d'utilisateurs //
/////////////////////////////////////////////
elseif ( ( $action == 'groupes' ) && ctype_digit($id = $_REQUEST['id'] ?? '') )  {

  // Vérification que l'identifiant est valide
  $resultat = $mysqli->query("SELECT nom, mails, notes, utilisateurs FROM groupes WHERE id = $id");
  if ( !$resultat->num_rows )
    exit('{"etat":"nok","message":"Identifiant non valide"}');
  $r = $resultat->fetch_assoc();
  $resultat->free();

  // Suppression
  if ( isset($_REQUEST['supprime']) )  {
    if ( !requete('groupes',"DELETE FROM groupes WHERE id = $id",$mysqli) )
      exit("{\"etat\":\"nok\",\"message\":\"Le groupe ${r['nom']} n'a pas été supprimé. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}');
    // Rechargement si on supprime le dernier groupe
    $resultat = $mysqli->query('SELECT id FROM groupes LIMIT 1');
    if ( $resultat->num_rows )  {
      $resultat->free();
      exit("{\"etat\":\"ok\",\"message\":\"Le groupe ${r['nom']} a été supprimé.\"}");
    }
    exit($_SESSION['message'] = "{\"etat\":\"ok\",\"message\":\"Le groupe ${r['nom']} a été supprimé.\",\"reload\":\"1\"}");
  }

  // Modification
  if ( isset($_REQUEST['champ']) )  {
    switch ( $champ = $_REQUEST['champ'] )  {
      case 'nom':
        if ( !($val = trim($mysqli->real_escape_string($_REQUEST['val'] ?? ''))) )
          exit("{\"etat\":\"nok\",\"message\":\"Le nom du groupe ${r['nom']} n'a pas été modifié&nbsp;: le nom ne peut pas être vide.\"}");
        exit( requete('groupes',"UPDATE groupes SET nom = '$val', nom_nat = '".zpad($val)."' WHERE id = $id",$mysqli) 
          ? "{\"etat\":\"ok\",\"message\":\"Le nom du groupe ${r['nom']} a été modifié.\"}"
          : "{\"etat\":\"nok\",\"message\":\"Le nom du groupe ${r['nom']} n'a pas été modifié. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}' );
      case 'mails':
      case 'notes':
        $val = intval( ( $_REQUEST['val'] ?? 0 ) > 0 );
        exit( requete('groupes',"UPDATE groupes SET $champ = $val WHERE id = $id",$mysqli)
          ? "{\"etat\":\"ok\",\"message\":\"Le groupe ${r['nom']} a été modifié.\"}"
          : "{\"etat\":\"nok\",\"message\":\"Le groupe ${r['nom']} n'a pas été modifié. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}' );
      case 'utilisateurs':
        if ( !($ids = implode(',',array_filter(explode(',',$_REQUEST['uids'] ?? ''),'ctype_digit'))) )
          exit("{\"etat\":\"nok\",\"message\":\"La composition du groupe ${r['nom']} n'a pas été modifiée&nbsp;: le groupe ne peut pas être vide.\"}");
        // Vérification des identifiants d'utilisateurs - tous utilisateurs autorisés
        $resultat = $mysqli->query("SELECT GROUP_CONCAT(id) FROM utilisateurs WHERE FIND_IN_SET(id,'$ids')");
        $s = $resultat->fetch_row();
        $resultat->free();
        if ( !$s[0] )
          exit("{\"etat\":\"nok\",\"message\":\"La composition du groupe ${r['nom']} n'a pas été modifiée&nbsp;: le groupe ne peut pas être vide.\"}");
        exit( requete('groupes',"UPDATE groupes SET utilisateurs = '${s[0]}' WHERE id = $id",$mysqli)
          ? "{\"etat\":\"ok\",\"message\":\"La composition du groupe ${r['nom']} a été modifié.\"}"
          : "{\"etat\":\"nok\",\"message\":\"La composition du groupe ${r['nom']} n'a pas été modifié. Erreur MySQL n°".$mysqli->errno.', «'.$mysqli->error.'»."}' );
    }
  }
  exit('{"etat":"nok","message":"Champ non valide"}');
}

//////////////////////////////////////
// Ajout d'un groupe d'utilisateurs //
//////////////////////////////////////
elseif ( ( $action == 'ajout-groupe' ) && ( $nom = trim($mysqli->real_escape_string($_REQUEST['nom'] ?? '')) ) && ( $ids = implode(',',array_filter(explode(',',$_REQUEST['uids'] ?? ''),'ctype_digit')) )  )  {

  // Vérification des identifiants d'utilisateurs - tous utilisateurs autorisés
  $resultat = $mysqli->query("SELECT GROUP_CONCAT(id) FROM utilisateurs WHERE FIND_IN_SET(id,'$ids')");
  $r = $resultat->fetch_row();
  $resultat->free();
  if ( !$r[0] )
    exit('{"etat":"nok","message":"Un groupe ne peut pas être vide."}');
  // Champs mails et notes
  $mails = intval(isset($_REQUEST['mails']));
  $notes = intval(isset($_REQUEST['notes']));
  // Écriture
  exit ( requete('groupes',"INSERT INTO groupes SET nom = '$nom', nom_nat = '".zpad($nom)."', mails = $mails, notes = $notes, utilisateurs = '${r[0]}'",$mysqli)
    ? ( $_SESSION['message'] = '{"etat":"ok","message":"Le groupe a été ajouté.","reload":"1"}' )
    : '{"etat":"nok","message":"Le groupe n\'a pas été ajouté. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}' );
}

//////////////////////////////
// Modification du planning //
//////////////////////////////
elseif ( $action == 'planning' )  {

  // Récupérations des donnees envoyées
  $colles = ( isset($_REQUEST['colles']) ) ? $_REQUEST['colles'] : array();
  $oraux = ( isset($_REQUEST['oraux']) ) ? $_REQUEST['oraux'] : array();
  $vacances = ( isset($_REQUEST['vacances']) ) ? $_REQUEST['vacances'] : array();
  // Valeur maximale du code vacances
  $resultat = $mysqli->query('SELECT MAX(id) FROM vacances');
  $vmax = $resultat->fetch_row()[0];
  $resultat->free();
  // Comparaison et modification
  // Les semaines non modifiables parce que déjà avec des colles (programmes ou notes) ne sont normalement pas envoyées.
  // On peut les exclure silencieusement.
  $resultat = $mysqli->query('SELECT id, DATE_FORMAT(debut,\'%d/%m/%Y\') AS debut, colle, vacances 
                              FROM semaines LEFT JOIN (SELECT DISTINCT semaine FROM notescolles UNION SELECT DISTINCT semaine FROM progcolles ) AS np ON semaine = id
                              GROUP BY id HAVING COUNT(semaine) = 0 ORDER BY id');
  $modif = array();
  while ( $r = $resultat->fetch_assoc() )  {
    $v = intval( ( ctype_digit($v = $vacances[$r['id']]) && ( $v <= $vmax ) ) ? $v : 0 );
    $c = intval( isset($colles[$r['id']]) && !$v ) ?: 2*intval( isset($oraux[$r['id']]) && !$v );
    if ( ( $c != $r['colle'] ) || ( $v != $r['vacances'] ) )  {
      requete('semaines',"UPDATE semaines SET colle = $c, vacances = $v WHERE id = ${r['id']}",$mysqli);
      $modif[] = "semaine du ${r['debut']}";
    }
  }
  $resultat->free();
  // Message à afficher
  exit( $modif ? '{"etat":"ok","message":"Les modifications ont été réalisées ('.implode(', ',$modif).')."}' : '{"etat":"ok","message":"Aucune modification n\'a été réalisée."}');
}

///////////////////////////////////////////
// Modification des préférences globales //
///////////////////////////////////////////
elseif ( ( $action == 'prefsglobales' ) && in_array($id = $_REQUEST['id'] ?? '',array('titre','transferts','agenda','mails','creation_compte'), true) )  {

  // Titre du Cahier donc de la page n°1
  if ( ( $id == 'titre' ) && ( $titre = mb_strtoupper(mb_substr($titre = trim(strip_tags($mysqli->real_escape_string($_REQUEST['titre'] ?? ''))) ,0,1)).mb_substr($titre,1) ) )  {
    if ( !requete('pages',"UPDATE pages SET titre = '$titre' WHERE id = 1",$mysqli) )
      exit('{"etat":"nok","message":"Le titre du Cahier n\'a pas été modifié. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');
    exit('{"etat":"ok","message":"Le titre du Cahier a été modifié."}');
  }

  // Transferts généraux : 2-> désactivée ; 1-> possible (0 ou 1 dans la base)
  // Protection à ajuster en fonction des transferts existants
  // Bout identique aux traitements de ajaxprofsadmin.php, section matières
  if ( ( $id == 'transferts' ) &&  in_array($transferts = $_REQUEST['transferts_general'], array(1,2)) )  {
    
    // Récupération des valeurs précédentes
    $resultat = $mysqli->query('SELECT val FROM prefs WHERE nom=\'transferts_general\'');
    $transferts_ancien = $resultat->fetch_row()[0];
    $resultat->free();
    if ( $transferts == max(1,$transferts_ancien) )
      exit('{"etat":"nok","message":"Le réglage demandé est déjà celui en place. Aucune modification n\'a été réalisée."}');
    // Désactivation
    if ( $transferts == 2 )  {
      if ( !requete('prefs','UPDATE prefs SET val = 2 WHERE nom = \'transferts_general\'',$mysqli) || !requete('prefs','UPDATE prefs SET val = 32 WHERE nom = \'transferts_general_protection\'',$mysqli) )
        exit('{"etat":"nok","message":"L\'accès aux transferts de documents généraux n\'a pas été désactivé. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');
      $action = 'désactivé';
    }
    else  {
      if ( !requete('prefs','UPDATE prefs SET val = IF( (SELECT id FROM transferts WHERE matiere = 0 LIMIT 1) ,1,0 ) WHERE nom = \'transferts_general\'',$mysqli)
        || !requete('prefs','UPDATE prefs SET val = IFNULL( (SELECT 16-(BIT_OR(type)<<1|2) FROM transferts WHERE matiere = 0), 32) WHERE nom = \'transferts_general_protection\'',$mysqli) )
        exit('{"etat":"nok","message":"L\'accès aux transferts de documents généraux n\'a pas été activé. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');
      $action = 'activé';
    }
    requete('utilisateurs','UPDATE utilisateurs SET menuelements=\'\'',$mysqli);
    exit("{\"etat\":\"ok\",\"message\":\"L'accès aux transferts de documents généraux a été $action.\"}");
  }

  // Préférences de l'agenda : protection globale et nombre d'événements sur la page d'accueil
  if ( ( $id == 'agenda' ) && in_array($vue = $_REQUEST['vue'], array(1,2)) && ctype_digit($protection = $_REQUEST['protection'] ?? '') && ctype_digit($edition = $_REQUEST['edition'] ?? '') && ctype_digit($nbmax = $_REQUEST['nbmax'] ?? '') && ctype_digit($datemax = $_REQUEST['datemax'] ?? '') )  {
    
    // Récupération des valeurs précédentes
    // agenda_datemax, agenda_edition, agenda_nbmax, agenda_protection, agenda_vue
    $resultat = $mysqli->query('SELECT GROUP_CONCAT(val ORDER BY nom) FROM prefs WHERE nom LIKE \'agenda%\'');
    $prefsagenda = explode(',',$resultat->fetch_row()[0]);
    $resultat->free();
    
    // Les professeurs ont obligatoirement accès à l'agenda, sauf si désactivé
    if ( $protection )
      $protection = $protection & 15 ?: 32;
    // Validation de l'édition. La protection doit obligatoirement inclure l'édition.
    $edition = ( $edition ) ? ($edition = ($edition-1) & (32-($protection?:1)) & 30) + ($edition>0) : 0;

    // Modifications
    $modif = false;
    foreach ( array('datemax','edition','nbmax','protection','vue') as $i => $pref )  {
      if ( ( $val = ${$pref} ) != $prefsagenda[$i] )  {
        $modif = true;
        if ( !requete('prefs',"UPDATE prefs SET val = $val WHERE nom='agenda_$pref'",$mysqli) )
          exit('{"etat":"nok","message":"Les préférences de l\'agenda n\'ont pas été modifiées. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');
        if ( $pref == 'protection' )  {
          // Si agenda anciennement ou nouvellement désactivé, on réactive en propageant
          if ( ( $protection == 32 ) || ( $prefsagenda[3] == 32 ) )
            $_REQUEST['propagation'] = 1;
          // Sauf si propagation de la protection (voir plus bas), on change
          // l'édition de chaque événement. 
          elseif ( !isset($_REQUEST['propagation']) )  {
            $masque = ( 32 - ($protection?:1) ) & 30;
            requete('agenda',"UPDATE agenda SET edition = IF(edition, (edition-1) & $masque, 0)",$mysqli);
            requete('agenda',"UPDATE agenda SET edition = IF(edition, edition+1, 0)",$mysqli);
            // Modification des éléments des menus
            $mysqli->query('UPDATE utilisateurs SET menuelements=\'\'');
          }
        }
        // Modification des préférences d'affichage sur la page d'accueil
        if ( $pref == 'nbmax' )
          requete('agenda-types',"UPDATE `agenda-types` SET index_nbmax = $val WHERE index_nbmax > $val",$mysqli);
        if ( $pref == 'datemax' )
          requete('agenda-types',"UPDATE `agenda-types` SET index_datemax = $val WHERE index_datemax > $val",$mysqli);
      }
    }
    
    // Propagation de la protection : modification des événements
    if ( isset($_REQUEST['propagation']) )  {
      $modif = true;
      requete('agenda',"UPDATE agenda SET protection = $protection, edition = $edition",$mysqli);
      // Mise à jour de la table recents et des flux RSS
      requete('recents',"UPDATE recents SET protection = $protection WHERE type = 4",$mysqli);
      rss($mysqli,0,$protection,$prefsagenda[3]);
      $mysqli->query('UPDATE utilisateurs SET menuelements=\'\'');
    }
    
    // Pas de modification
    if ( !$modif )
      exit('{"etat":"nok","message":"Les réglages demandés sont déjà ceux en place. Aucune modification n\'a été réalisée."}');
    // Si on vient de la page "agenda.php", on doit recharger après
    if ( isset($_REQUEST['origine']) && ( $_REQUEST['origine'] == 'agenda') ) 
      exit($_SESSION['message'] = '{"etat":"ok","message":"Les préférences de l\'agenda ont été modifiées.","reload":"1"}');    
    exit('{"etat":"ok","message":"Les préférences de l\'agenda ont été modifiées."}');
  }
  
  // Préférence d'envoi de mail, venant de utilisateurs-mails.php
  // $depuis : numéro du groupe expéditeur traité
  // $vers : numéro du groupe destinataire traité ou 0 pour les traiter tous
  // $ok : 1 pour autoriser, 0 pour interdire
  elseif ( ( $id == 'mails' ) && in_array($depuis = intval($_REQUEST['depuis'] ?? ''), array(2,3,4,5)) && in_array($vers = intval($_REQUEST['vers'] ?? ''), array(0,2,3,4,5)) && ctype_digit($ok = $_REQUEST['val'] ?? '') )  {
    // Masque : bits à modifier
    $masque = ( $vers ) ? 1 << 4*($depuis-2)+$vers-2 : 15 << 4*($depuis-2);
    // Récupération de la valeur originale
    $resultat = $mysqli->query('SELECT val FROM prefs WHERE nom = "autorisation_mails"');
    $val_orig = $resultat->fetch_row()[0];
    // Modification
    $val = ( $ok ) ? $val_orig | $masque : $val_orig & ( 65535 - $masque );
    if ( $val == $val_orig )
      exit('{"etat":"nok","message":"Aucune action effectuée : les autorisations d\'envoi demandées sont déjà celles en place."}');
    if ( requete('prefs',"UPDATE prefs SET val = $val WHERE nom='autorisation_mails'",$mysqli) && $mysqli->query('UPDATE utilisateurs SET menuelements=\'\'') )
      exit('{"etat":"ok","message":"Les autorisations d\'envoi de courriels ont été modifiées."}');
    exit('{"etat":"nok","message":"Les autorisations d\'envoi de courriels n\'ont pas été modifiées. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');
  }
  
  // Préférence de création de compte, venant de utilisateurs.php 
  elseif ( $id == 'creation_compte' )  {
    $val = intval( isset($_REQUEST['val']) && ( $_REQUEST['val'] > 0 ) );
    if ( requete('prefs',"UPDATE prefs SET val = $val WHERE nom='creation_compte'",$mysqli) )
      exit('{"etat":"ok","message":"Les créations de compte ont été '.( $val ? 'autorisées' : 'interdites' ).'."}');
    exit('{"etat":"nok","message":"La possibilité de création de comptes n\'a pas été modifiée. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');
  }
}

// Sans action
exit('{"etat":"nok","message":"Aucune action effectuée"}');
?>
