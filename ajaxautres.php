<?php
// Sécurité : script obligatoirement inclus par ajax.php
if ( !defined('OK') )  exit();

// Script d'exécution des commandes ajax pour des actions diverses
// Autorisation : élève au minimum. Vérification en fonction de l'élément à éditer.
// Nécessite d'être connecté en connexion normale.
if ( ( $autorisation < 2 ) || !connexionlight() )
  exit( '{"etat":"nok","message":"Aucune action effectuée"}' );
$mysqli = connectsql(true);
// Spécifications pour les manipulations de caractères sur 2 octets (accents)
mb_internal_encoding('UTF-8');

///////////////////////
// Envoi de courriel //
///////////////////////
if ( ( $action == 'courriel' ) && isset($_REQUEST['id-copie']) && isset($_REQUEST['sujet']) && isset($_REQUEST['texte']) )  {
  //exit(var_dump($_FILES['pj']));
  //  exit('{"etat":"nok","message":"Pas de sujet : courriel non envoyé. Nombre de fichiers : '.count($_FILES['pj']['name']).'"}');
  // Vérification des données
  if ( !($sujet = $_REQUEST['sujet']) )
    exit('{"etat":"nok","message":"Pas de sujet : courriel non envoyé"}');
  elseif ( !($texte = $_REQUEST['texte']) )
    exit('{"etat":"nok","message":"Pas de texte : courriel non envoyé"}');
  // Vérification de l'adresse électronique
  $resultat = $mysqli->query("SELECT mailexp, mail FROM utilisateurs WHERE id = ${_SESSION['id']}");
  $u = $resultat->fetch_assoc();
  $resultat->free();
  if ( !$u['mailexp'] || !filter_var($u['mail'],FILTER_VALIDATE_EMAIL) )
    exit('{"etat":"nok","message":"Compte mal réglé&nbsp;: nom ou adresse d\'expédition manquants"}');
  $mailexp = encode_mailheaders($u['mailexp']);
  // Vérification de l'autorisation, et récupération de l'autorisation en fonction du type d'utilisateur  
  $resultat = $mysqli->query('SELECT val FROM prefs WHERE nom = "autorisation_mails"');
  if ( !( $aut_envoi = $resultat->fetch_row()[0] >> 4*($autorisation-2) & 15 ) )
    exit('{"etat":"nok","message":"L\'envoi de courriel n\'est pas autorisé."}');
  $aut_dest = implode(',',array_keys(str_split('00'.strrev(decbin($aut_envoi))),1));
  $resultat->free();
  // Récupération des destinataires, comptes valides uniquement
  $resultat = $mysqli->query("SELECT id, mail, IF(LENGTH(mailexp),mailexp, CONCAT(prenom,' ',nom)) AS mailexp FROM utilisateurs WHERE mail != '' AND mdp > '0' AND id != ${_SESSION['id']} AND FIND_IN_SET(autorisation%10,'$aut_dest') ORDER BY autorisation%10 DESC, nom");
  $mysqli->close();
  $utilisateurs = array();
  while ( $r = $resultat->fetch_assoc() )
    $utilisateurs[$r['id']] = $r;
  $resultat->free();
  $dests = '';
  $ids = explode(',',$_REQUEST['id-copie']);
  foreach ( $ids as $k => $i )
    if ( isset($utilisateurs[$i]) )  {
      // Tous les 10 destinataires, on ajoute un retour à la ligne pour respecter la RFC 5322
      $dests .= ( ( $k%10 == 9 ) ? "\r\n\t" : '' ) . encode_mailheaders($utilisateurs[$i]['mailexp'])." <{$utilisateurs[$i]['mail']}>, ";
      unset($utilisateurs[$i]);
    }
  // Formatage des destinataires et initialisation des copies cachées
  $copie = 0;
  if ( $dests )  {
    $dests = substr($dests,0,-2);
    $bcc = ( $copie = intval(isset($_REQUEST['copie'])) ) ? "$mailexp <{$u['mail']}>, " : '';
    $n1 = substr_count($dests,'<');
  }
  // Si aucun destinataire, on met l'utilisateur en destinataire
  else  {
    $dests = "$mailexp <{$u['mail']}>";
    $bcc = '';
    $n1 = 0;
    $copie = 2;
  }
  // Fabrication du mail
  if ( $_REQUEST['id-bcc'] )  {
    $ids = explode(',',$_REQUEST['id-bcc']);
    foreach ( $ids as $i )
      if ( isset($utilisateurs[$i]) )  {
        $bcc .= encode_mailheaders($utilisateurs[$i]['mailexp'])." <{$utilisateurs[$i]['mail']}>, ";
        unset($utilisateurs[$i]);
      }
  }
  $bcc = ( $bcc ) ? 'Bcc: '.substr($bcc,0,-2) : '';
  $exp = 'nepasrepondre'.strstr($mailadmin,'@');
  // Comptage des destinataires avant envoi et arrêt si personne
  $n2 = substr_count($bcc,'<') - ( $copie == 1 );
  if ( !$n2 && ( $copie == 2 ) )
    exit('{"etat":"nok","message":"Le courriel n\'a pas été envoyé, il n\'y a pas d\'autre destinataire valide que vous."}');
  // Envoi du mail différent en présence de pièces jointes
  if ( !isset($_FILES['pj']) )
    mail($dests,encode_mailheaders($sujet),$texte,"MIME-Version: 1.0\r\nList-Unsubscribe: <mailto:contact".strstr($mailadmin,'@')."?subject=unsubscribe>\r\nContent-type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\nFrom: $mailexp <$exp>\r\nReply-To: $mailexp <${u['mail']}>\r\n$bcc\r\n","-f$exp");
  else  {
    $sep = md5(uniqid());
    $corps = "--$sep\r\nContent-type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n$texte\r\n\r\n";
    // Traitement de chaque fichier envoyé 
    $tailletotale = 0;
    for ( $i = 0 ; $i < ( $n = count($_FILES['pj']['tmp_name']) ) ; $i++ )  {
      $ext = strtolower(trim(htmlspecialchars(strrchr($_FILES['pj']['name'][$i],'.'),ENT_COMPAT)));
      $nom = htmlspecialchars(trim(substr(basename(str_ireplace(array($ext,'\\','/'), array('','_','_'), ( $_REQUEST['nom'][$i] ?? '' ) ?: $_FILES['pj']['name'][$i] )),0,100)),ENT_COMPAT);
      if ( !is_uploaded_file($_FILES['pj']['tmp_name'][$i]) || !( $contenu = file_get_contents($_FILES['pj']['tmp_name'][$i]) ) )
        exit("{\"etat\":\"nok\",\"message\":\"Le courriel n'a pas été envoyé, le fichier <em>$nom$ext</em> n'a pas pu être correctement récupéré.\"}");
      if ( $_FILES['pj']['size'][$i] > 5*1048576 )
        exit("{\"etat\":\"nok\",\"message\":\"Le courriel n'a pas été envoyé, le fichier <em>$nom$ext</em> est trop lourd (dépasse 5&nbsp;Mo).\"}");
      $tailletotale += $_FILES['pj']['size'][$i];
      $nom = encode_mailheaders($nom.$ext);
      $corps .= "--$sep\r\nContent-Type: ".transforme_extension(substr($ext,1),1)."; name=\"$nom\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"$nom\"\r\n\r\n".chunk_split(base64_encode($contenu))."\r\n\r\n";
    }
    if ( $tailletotale > 20*1048576 )
      exit('{"etat":"nok","message":"Le courriel n\'a pas été envoyé, les fichiers sont trop lourds (plus de 20&nbsp;Mo au total)."}');
    mail($dests,encode_mailheaders($sujet),"$corps--$sep--","MIME-Version: 1.0\r\nList-Unsubscribe: <mailto:contact".strstr($mailadmin,'@')."?subject=unsubscribe>\r\nContent-type: multipart/mixed; boundary=\"$sep\"\r\nFrom: $mailexp <$exp>\r\nReply-To: $mailexp <${u['mail']}>\r\n$bcc\r\n","-f$exp");
  }
  // Message de confirmation d'envoi avec décompte
  $message = ( $n2 ? "(dont $n2 en copie cachée)" : '' );
  $message = 'Le courriel a été envoyé à '.($n1+$n2).' destinataire'. ( ( $n1+$n2 > 1 ) ? 's' : '' ) ."$message.";
  if ( $copie == 1 )
    $message .= '<br>Vous êtes en copie cachée de ce courriel.';
  else if ( $copie == 2 )
    $message .= '<br>Vous êtes l\'unique destinataire visible de ce courriel.';
  exit($_SESSION['message'] = "{\"etat\":\"ok\",\"message\":\"$message\",\"reload\":\"2\"}");
}

//////////////////////////////
// Préférences personnelles //
//////////////////////////////
elseif ( ( $action == 'prefsperso' ) && isset($_REQUEST['id']) )  {

  // Fonction de fabrication de la partie modifiante de la requête
  function fabriqueupdate($requete,$mysqli)  {
    $chaine = '';
    foreach ($requete as $champ=>$val)
      $chaine .= ",$champ = '".$mysqli->real_escape_string($val).'\'';
    return substr($chaine,1);
  }
  
  // Récupération des données de l'utilisateur
  $resultat = $mysqli->query("SELECT * FROM utilisateurs WHERE id = ${_SESSION['id']}");
  $r = $resultat->fetch_assoc();
  $resultat->free();

  // Premier cadre de modifications de prefs.php : mot de passe
  if ( ( $_REQUEST['id'] == 'mdp' ) && isset($_REQUEST['mdp1']) && isset($_REQUEST['mdp2']) )  {
    if ( !($mdp1 = $_REQUEST['mdp1']) || ( $mdp1 != $_REQUEST['mdp2'] ) )
      exit('{"etat":"nok","message":"Le nouveau mot de passe et la confirmation donnés sont différents"}');
    $requete = array('mdp'=>sha1($mdp.$mdp1));
    // Token de connexion automatique à renouveler si existant
    if ( $_SESSION['permconn'] )  {
      $permconn = '';
      for ( $i = 0; $i < 10; $i++ )
        $permconn .= '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ'[random_int(0,61)];
      $requete['permconn'] = $permconn;
    }
    if ( !$requete )
      exit('{"etat":"nok","message":"Aucune modification à faire"}');
    # Éxécution
    if ( requete('utilisateurs','UPDATE utilisateurs SET ' .fabriqueupdate($requete,$mysqli) . " WHERE id = ${_SESSION['id']}",$mysqli) )  {
      if ( isset($permconn) )
        setcookie('CDP_SESSION_PERM',$permconn,array('expires'=>time()+31536000,'path'=>$chemin,'domain'=>$domaine,'secure'=>true,'httponly'=>false,'samesite'=>'Lax'));
      // Si interface globale activée, mise à jour
      if ( $interfaceglobale )  {
        include("${interfaceglobale}majutilisateurs.php");
        majutilisateurs($_SESSION['id'],$requete);
      }
      exit($_SESSION['message'] = '{"etat":"ok","message":"Votre mot de passe a été modifié.","reload":"1"}');
    }
    exit('{"etat":"nok","message":"Votre mot de passe n\'a pas été modifié. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');
  }

  // Deuxième cadre de modifications de prefs.php : identité
  if ( ( $_REQUEST['id'] == 'identite' ) && isset($_REQUEST['prenom']) && isset($_REQUEST['nom']) && isset($_REQUEST['login']) )  {
    // Vérification et nettoyage des données
    if ( !($prenom = mb_convert_case(strip_tags(trim($_REQUEST['prenom'])),MB_CASE_TITLE))
      || !($nom = mb_convert_case(strip_tags(trim($_REQUEST['nom'])),MB_CASE_TITLE)) 
      || !($login = mb_strtolower(str_replace(' ','_',strip_tags(trim($_REQUEST['login']))))) )
      exit('{"etat":"nok","message":"Votre compte n\'a pas été modifié. Prénom, nom et identifiant doivent rester non vides."}');
    // Construction de la requête
    $requete = array();
    if ( $login != $_SESSION['login'] )  {
      // Vérification que le login n'existe pas déjà : si oui, on ne fait pas la modification silencieusement
      $resultat = $mysqli->query('SELECT id FROM utilisateurs WHERE login = \''.$mysqli->real_escape_string($login).'\'');
      if ( !$resultat->num_rows )
        $requete['login'] = $login;
      else
        $resultat->free();
    }
    if ( $prenom != $r['prenom'] )
      $requete['prenom'] = $prenom;
    if ( $nom != $r['nom'] )
      $requete['nom'] = $nom;
    if ( !$requete )
      exit('{"etat":"nok","message":"Aucune modification à faire"}');
    if ( requete('utilisateurs','UPDATE utilisateurs SET ' .fabriqueupdate($requete,$mysqli) . " WHERE id = ${_SESSION['id']}",$mysqli) )  {
      // Mise à jour des données de session et cookies
      if ( isset($requete['login']) )
        $_SESSION['login'] = $login;
      // Si interface globale activée, mise à jour
      if ( $interfaceglobale )  {
        include("${interfaceglobale}majutilisateurs.php");
        majutilisateurs($_SESSION['id'],$requete);
      }
      exit('{"etat":"ok","message":"Vos préférences d\'identité ont été modifiées."}');
    }
    exit('{"etat":"nok","message":"Votre compte n\'a pas été modifié. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');
  }
  
  // Troisième cadre de modifications de prefs.php : adresse électronique
  if ( ( $_REQUEST['id'] == 'mail' ) && isset($_REQUEST['mail']) && isset($_REQUEST['mailexp']))  {
    // Vérification et nettoyage des données
    if ( !filter_var($mail = mb_strtolower(trim($_REQUEST['mail'])),FILTER_VALIDATE_EMAIL) )
      exit('{"etat":"nok","message":"Votre compte n\'a pas été modifié. L\'adresse électronique doit être valide et non vide."}');
    if ( !( $mailexp = strip_tags(trim($_REQUEST['mailexp'])) ) )
      exit('{"etat":"nok","message":"Votre compte n\'a pas été modifié. Le nom d\'expéditeur/destinataire doit être non vide."}');
    // Pas de modification de l'adresse électronique
    if ( $mail == $r['mail'] )  {
      if ( $mailexp == $r['mailexp'] )
        exit('{"etat":"nok","message":"Aucune modification à faire"}');
      // Modification du nom d'expéditeur/destinataire uniquement
      exit( requete('utilisateurs','UPDATE utilisateurs SET mailexp = \'' .$mysqli->real_escape_string($mailexp)."' WHERE id = ${_SESSION['id']}",$mysqli)
            ? '{"etat":"ok","message":"Votre nom affiché dans les courriels a été modifié."}'
            : '{"etat":"nok","message":"Votre compte n\'a pas été modifié. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}' );
    }
    // Si pas de code de confirmation : envoi de courriel
    if ( !isset($_REQUEST['confirmation']) )  {
      // On ajoute 15 minutes au temps utilisé : de xh00 à xh45,
      // on a jusqu'à (x+1)h, de xh45 à (x+1)h on a jusqu'à (x+2)h
      $t = time() + 900;
      // Vérification que l'adresse n'est pas déjà utilisée
      // Envoi d'un faux message d'accord, pour ne pas montrer que cette adresse est déjà dans la base
      $resultat = $mysqli->query('SELECT id FROM utilisateurs WHERE mail = \''.$mysqli->real_escape_string($mail)."' AND id != ${_SESSION['id']}");
      if ( $resultat->num_rows )
        exit('{"etat":"confirm_mail","message":"<strong>Un courriel vient de vous être envoyé à l\'adresse <code>'.$mail.'</code>.</strong><br>Il contient un code, valable jusqu\'à '.date('G\h00',$t+3600).', que vous devez copier-coller ci-dessous pour réaliser l\'opération.<br>Si vous ne voyez rien, pensez à regarder dans les courriels marqués comme spam. Certains serveurs retardent jusqu\'à 10 minutes l\'arrivée des messages, normalement la première fois uniquement."}');
      $p = substr(sha1($chemin.$mdp.date('Y-m-d-H',$t).$mail),0,8);
      mail($mail,'=?UTF-8?B?'.base64_encode('[Cahier de Prépa] Changement d\'adresse électronique').'?=',
"Bonjour

Vous avez demandé à modifier l'adresse électronique liée à votre compte sur le Cahier de Prépa <https://$domaine$chemin>.

Cette demande nécessite la vérification que vous possédez l'adresse à laquelle vous recevez ce courriel.

Pour ce faire, vous devez copier, sur la page qui a généré ce courriel, le code suivant :

     $p

Ce code est valable jusqu'à ".date('G\h00',$t+3600).'.

Si cette demande ne vient pas de vous, merci d\'ignorer ce courriel et éventuellement de le signaler à l\'administrateur en répondant à ce courriel.

Cordialement,
-- 
Cahier de Prépa
  ','From: =?UTF-8?B?'.base64_encode('Cahier de Prépa')."?= <$mailadmin>\r\nList-Unsubscribe: <mailto:contact".strstr($mailadmin,'@')."?subject=unsubscribe>\r\nContent-type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit","-f$mailadmin");
      exit('{"etat":"confirm_mail","message":"<strong>Un courriel vient de vous être envoyé à l\'adresse <code>'.$mail.'</code>.</strong><br>Il contient un code, valable jusqu\'à '.date('G\h00',$t+3600).', que vous devez copier-coller ci-dessous pour réaliser l\'opération.<br>Si vous ne voyez rien, pensez à regarder dans les courriels marqués comme spam. Certains serveurs retardent jusqu\'à 10 minutes l\'arrivée des messages, normalement la première fois uniquement."}');
    }
    // $_REQUEST['p'] est obligatoirement défini. Il s'agit du sha1 de $chemin.$mdp.date('Y-m-d-H').$mail
    if ( ( ($p=$_REQUEST['confirmation']) != substr(sha1($chemin.$mdp.date('Y-m-d-H').$mail),0,8) ) && ( $p != substr(sha1($chemin.$mdp.date('Y-m-d-H',time()+900).$mail),0,8) ) )
      exit('{"etat":"nok","message":"Le code saisi n\'est pas correct. Si vous avez dépassé le délai, vous devez recommencer la procédure."}');
    // Modification
    $requete = ( $mailexp != $r['mailexp'] ) ? ', mailexp = \''.$mysqli->real_escape_string($mailexp).'\'' : '';
    if ( requete('utilisateurs','UPDATE utilisateurs SET mail = \''.$mysqli->real_escape_string($mail)."'$requete WHERE id = ${_SESSION['id']}",$mysqli) )  {
      // Si interface globale activée, mise à jour
      if ( $interfaceglobale )  {
        include("${interfaceglobale}majutilisateurs.php");
        majutilisateurs($_SESSION['id'],array('mail'=>$mail));
      }
      exit($_SESSION['message'] = '{"etat":"ok","message":"Vos préférences de courriel ont été modifiées.","reload":"1"}');
    }
    exit('{"etat":"nok","message":"Votre compte n\'a pas été modifié. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');
  }
  
  // Quatrième cadre de modifications de prefs.php : réglages techniques
  if ( ( $_REQUEST['id'] == 'reglages' ) && isset($_REQUEST['timeout']) && ctype_digit($timeout = $_REQUEST['timeout']) )  {
    $requete = array();
    // Token de connexion automatique à ajouter si non déjà présent et demandé
    if ( !$_SESSION['permconn'] && isset($_REQUEST['permconn']) )  {
      $permconn = '';
      for ( $i = 0; $i < 10; $i++ )
        $permconn .= '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ'[random_int(0,61)];
      $requete['permconn'] = $permconn;
    }
    elseif ( $_SESSION['permconn'] && !isset($_REQUEST['permconn']) )
      $requete['permconn'] = '';
    // Mailcopie
    if ( ($mailcopie = intval(isset($_REQUEST['mailcopie']))) != $r['mailcopie'] )
      $requete['mailcopie'] = $mailcopie;
    // Timeout
    if ( ( $timeout = ( $timeout > 15 ) ? $timeout : 900 ) != $_SESSION['timeout'] )
      $requete['timeout'] = $timeout;
    if ( !$requete )
      exit('{"etat":"nok","message":"Aucune modification à faire"}');
    # Éxécution
    if ( requete('utilisateurs','UPDATE utilisateurs SET ' .fabriqueupdate($requete,$mysqli) ." WHERE id = ${_SESSION['id']}",$mysqli) )  {
      // Mise à jour des données de session et cookies
      if ( isset($requete['timeout']) )  {
        $_SESSION['timeout'] = $timeout;
        $_SESSION['time'] = time()+$_SESSION['timeout'];
      }
      if ( isset($requete['permconn']) )  {
        $_SESSION['permconn'] = $permconn;
        setcookie('CDP_SESSION_PERM',$permconn,array('expires'=>( $permconn ) ? time()+31536000 : time()-3600,'path'=>$chemin,'domain'=>$domaine,'secure'=>true,'httponly'=>false,'samesite'=>'Lax'));
      }
      exit('{"etat":"ok","message":"Les réglages techniques de votre compte ont été modifiés."}');
    }
    exit('{"etat":"nok","message":"Votre compte n\'a pas été modifié. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');
  }

  // Cinquième cadre de modifications de prefs.php : envoi de courriel
  if ( ( $_REQUEST['id'] == 'menumatieres' ) && ( $autorisation > 3 ) && isset($_REQUEST['matieres']) )  {
    // Matières associées
    $associees = explode(',',str_replace('c','',$r['matieres']));
    // Récupération des matières existantes
    $resultat = $mysqli->query('SELECT GROUP_CONCAT(id) FROM matieres');
    $possibles = explode(',',$resultat->fetch_row()[0]);
    $resultat->free();
    // Récupération des matières demandées
    $matieres = implode(',',array_merge($associees,array_filter($_REQUEST['matieres'],function($m) use($possibles,$associees) { return in_array($m,$possibles,true) && !in_array($m,$associees); })));
    if ( $matieres == $r['menumatieres'] )
      exit('{"etat":"nok","message":"Aucune modification à faire"}');
    // Éxécution
    if ( requete('utilisateurs',"UPDATE utilisateurs SET menumatieres = '$matieres', menuelements ='' WHERE id = ${_SESSION['id']}",$mysqli) )
      exit($_SESSION['message'] = '{"etat":"ok","message":"Vos matières supplémentaires dans le menu ont été modifiées.","reload":"1"}');
    exit('{"etat":"nok","message":"Votre compte n\'a pas été modifié. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');
  }

}

/////////////////////////////////////////////////////////////////////////////////
// Transfert multiple de documents correction/sujet (pour transfert de copies) //
/////////////////////////////////////////////////////////////////////////////////
elseif ( ( $action == 'ajout-transdocs' ) && isset($_FILES['fichier']) && isset($_REQUEST['id']) && ctype_digit($id = $_REQUEST['id']) )  {

  // Vérification de l'identifiant du transfert
  $resultat = $mysqli->query("SELECT lien, type, matiere, deadline > NOW() AS ok FROM transferts
                              WHERE id = $id AND FIND_IN_SET(matiere,'".str_replace('c','',$_SESSION['matieres']).'\')');
  if ( !$resultat->num_rows )
    exit('{"etat":"nok","message":"Identifiant de transfert non valide."}');
  $r = $resultat->fetch_assoc();
  $resultat->free();
  // Profs étant enregistrés en temps que colleurs : changement d'autorisation
  if ( ( $autorisation == 5 ) && !in_array($r['matiere'],explode(',',$_SESSION['matieres'])) )
    $autorisation = 3;
  // Vérification de l'autorisation
  // Élèves si sens = type&1 = 0 ; Profs si sens = 1
  // Colleurs et lycée si sens = 1 et autorisation
  if ( ( $autorisation == 2 ) && ( $r['type'] & 1 ) || ( $autorisation > 2 ) && ( ( $r['type'] & 1 == 0 ) || ( ($r['type']>>($autorisation-2)) & 1 == 0 ) ) )
    exit('{"etat":"nok","message":"Identifiant de transfert non valide."}');
  // Vérification de la deadline
  if ( !$r['ok'] )
    exit('{"etat":"nok","message":"Impossible d\'envoyer un document : c\'est trop tard ! La date limite de transfert dépassée."}');
  
  // Génération des identifiants d'élèves (vérification si colleur/lycée/prof)
  $n = count($_FILES['fichier']['tmp_name']);
  if ( !($_FILES['fichier']['tmp_name'][0]) )
    exit('{"etat":"nok","message":"Aucun fichier n\'a été envoyé."}');
  if ( $autorisation == 2 )  {
    $eids = array_fill(0,$n,array($_SESSION['id']));
    $utilisateur = 0;
  }
  else  {
    $resultat = $mysqli->query('SELECT GROUP_CONCAT(id) FROM utilisateurs WHERE autorisation = 2 AND mdp > "0"');
    $eleves = explode(',',$resultat->fetch_row()[0]);
    $resultat->free();
    $eids = array();
    for ( $i = 0 ; $i < $n ; $i++ )
      if ( $ids = array_filter($_REQUEST["eid$i"] ?? array(),function($id) use ($eleves) { return ctype_digit($id) && in_array($id,$eleves); }) )
        $eids[] = $ids;
    if ( count($eids) != $n )
      exit('{"etat":"nok","message":"Impossible d\'envoyer un/des documents : identifiants d\'élèves invalides."}');
    $utilisateur = $_SESSION['id'];
  }
  
  // Traitement de chaque fichier envoyé
  $ok = 0;
  $message = '';
  setlocale(LC_CTYPE, "fr_FR.UTF-8");
  for ( $i = 0 ; $i < $n ; $i++ )  {
    if ( !is_uploaded_file($_FILES['fichier']['tmp_name'][$i]) )  {
      $message .= '<br>Le document <em>'.htmlspecialchars($_FILES['fichier']['tmp_name'][$i]).'</em> n\'a pas été ajouté : le fichier a mal été envoyé. Vous devriez en informer l\'administrateur.';
      continue;
    }
    // Vérifications des données envoyées (on fait confiance aux utilisateurs connectés pour ne pas envoyer de scripts malsains)
    // $ext est limitée à 10 caractères
    $ext = htmlspecialchars(strtolower(substr(strrchr($_FILES['fichier']['name'][$i],'.'),1,10)),ENT_COMPAT) ?: '';
    // Gestion de la taille
    $taille = ( ( $taille = intval($_FILES['fichier']['size'][$i]/1024) ) < 1024 ) ? "$taille&nbsp;ko" : intval($taille/1024).'&nbsp;Mo';
    // Déplacement du document uploadé au bon endroit
    if ( !move_uploaded_file($_FILES['fichier']['tmp_name'][$i],"documents/${r['lien']}/{$i}_tmp.$ext") )  {
      $message .= '<br>Le document <em>'.htmlspecialchars($_FILES['fichier']['tmp_name'][$i]).'</em> n\'a pas été ajouté : problème d\'écriture du fichier. Vous devriez en informer l\'administrateur.';
      continue;
    }
    // Récupération des identifiants d'élèves. $eids_i est un tableau.
    $eids_i = $eids[$i];
    foreach ( $eids_i as $eid )  {
      $resultat = $mysqli->query("SELECT id FROM transdocs WHERE transfert = $id AND eleve = $eid");
      if ( ( $numero = $resultat->num_rows + 1 ) > 1 )
        $resultat->free();
      // Écriture MySQL
      if ( requete('transdocs',"INSERT INTO transdocs SET transfert = $id, eleve = $eid, utilisateur = $utilisateur, numero = $numero, upload = NOW(), taille = '$taille', ext = '$ext'",$mysqli) )  {
        link("documents/${r['lien']}/{$i}_tmp.$ext","documents/${r['lien']}/${eid}_".$mysqli->insert_id.".$ext");
        $ok++;
      }
      else
        $message .= '<br>Le document <em>'.htmlspecialchars($_FILES['fichier']['tmp_name'][$i]).'</em> n\'a pas été ajouté. Erreur MySQL n°'.$mysqli->errno.', «&nbsp;'.$mysqli->error.'&nbsp;».';
    }
    // Suppression du premier lien dur
    unlink("documents/${r['lien']}/{$i}_tmp.$ext");
  }
  // Message à renvoyer pour les élèves : un seul document, rechargement de page
  if ( ( $autorisation == 2 ) && $ok )
    exit($_SESSION['message'] = '{"etat":"ok","message":"Le document a bien été ajouté.","reload":"1"}');
  // Message à renvoyer ; pas de $_SESSION['message'] car pas de rechargement de la page
  if ( !$ok )
    exit("{\"etat\":\"nok\",\"message\":\"Aucun document n'a été envoyé.$message\"}");
  if ( $ok < $n )
    exit("{\"etat\":\"ok\",\"message\":\"Seuls $ok documents sur $n ont été ajoutés.$message\"}");
  exit(( $ok > 1 ) ? "{\"etat\":\"ok\",\"message\":\"Les $ok documents ont été ajoutés.\"}" : "{\"etat\":\"ok\",\"message\":\"Le document a été ajouté.\"}");
}

//////////////////////////////////////////////////////////
// Suppression de documents transférés, un ou plusieurs //
/////////////////////////////////..///////////////////////
elseif ( ( $action == 'suppr-transdocs' ) && isset($_REQUEST['transfert']) && ctype_digit($tid = $_REQUEST['transfert']) )  {

  // Vérification de l'identifiant du transfert
  $resultat = $mysqli->query("SELECT lien, type, matiere FROM transferts WHERE id = $tid AND FIND_IN_SET(matiere,'".str_replace('c','',$_SESSION['matieres']).'\')');
  if ( !$resultat->num_rows )
    exit('{"etat":"nok","message":"Identifiant de transfert non valide."}');
  $r = $resultat->fetch_assoc();
  $resultat->free();
  // Profs étant enregistrés en temps que colleurs : changement d'autorisation
  if ( ( $autorisation == 5 ) && !in_array($r['matiere'],explode(',',$_SESSION['matieres'])) )
    $autorisation = 3;
  // Vérification de l'autorisation
  // Élèves si sens = type&1 = 0 ; Profs si sens = 1
  // Colleurs et lycée si sens = 1 et autorisation
  if ( ( $autorisation == 2 ) && ( $r['type'] & 1 ) || ( $autorisation > 2 ) && ( ( $r['type'] & 1 == 0 ) || ( ($r['type']>>($autorisation-2)) & 1 == 0 ) ) )
    exit('{"etat":"nok","message":"Identifiant de transfert non valide."}');
  
  // Récupération du/des identifiants de document
  if ( !count($ids = array_filter(explode(',',$_REQUEST['ids'] ?? $_REQUEST['id'] ?? ''),'ctype_digit')) )
    exit('{"etat":"nok","message":"Identifiants de documents non valides."}');
  
  // Suppression document par document
  $ok = 0;
  $message = '';
  foreach ( $ids as $id )  {
    $resultat = $mysqli->query("SELECT eleve, utilisateur, numero, ext FROM transdocs WHERE transfert = $tid AND id = $id");
    if ( !$resultat->num_rows )  {
      $message .= '<br>Un document n\'a pas été supprimé : identifiant non valide.';
      continue;
    }
    $s = $resultat->fetch_assoc();
    $resultat->free();
    // Vérification de l'accès au document : élève si pour/de lui, colleur/lycée si de lui
    if ( ( $autorisation == 2 ) && ( $s['eleve'] != $_SESSION['id'] ) || ( $autorisation > 2 ) && ( $autorisation < 5 ) && ( $s['utilisateur'] != $_SESSION['id'] ) )  {
      $message .= '<br>Un document n\'a pas été supprimé : identifiant non valide.';
      continue;
    }
    // Suppression dans la base
    if( !requete('transdocs',"DELETE FROM transdocs WHERE id = $id",$mysqli) ||
        !requete('transdocs',"UPDATE transdocs SET numero = numero-1 WHERE transfert = $tid AND eleve = ${s['eleve']} AND numero > ${s['numero']}",$mysqli) )  {
      $message .= '<br>Un document n\'a pas été supprimé. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'».';
      continue;
    }
    // Suppression physique
    unlink("documents/${r['lien']}/${s['eleve']}_$id.${s['ext']}");
    $ok = $ok + 1;
  }
  // Message à renvoyer ; pas de $_SESSION['message'] car pas de rechargement de la page
  if ( !$ok )
    exit("{\"etat\":\"nok\",\"message\":\"Aucun document n'a été supprimé.$message\"}");
  $n = count($ids);
  if ( $ok < $n )
    exit("{\"etat\":\"ok\",\"message\":\"Seuls $ok documents sur $n ont été supprimés.$message\"");
  exit( ( $ok > 1 ) ? "{\"etat\":\"ok\",\"message\":\"Les $ok documents ont été supprimés.\"}" : "{\"etat\":\"ok\",\"message\":\"Le document a été supprimé.\"}");
}

///////////////////////////////////////////////////////////
// Modification de notes (accès colleurs et professeurs) //
///////////////////////////////////////////////////////////
elseif ( ( $action == 'notescolles' ) && ( ( $autorisation == 3 ) || ( $autorisation == 5 ) ) && ctype_digit($id = $_REQUEST['id'] ?? '') )  {

  // Vérification que l'identifiant est valide
  $resultat = $mysqli->query("SELECT matiere, DATE_FORMAT(jour,'%w%Y%m%e') AS date, jour,
                              DATE_FORMAT(rattrapage,'%Y-%m-%d') AS rattrapage, duree, releve>0 AS releve, description 
                              FROM heurescolles WHERE id = $id AND colleur = ${_SESSION['id']}");
  if ( !$resultat->num_rows )
    exit('{"etat":"nok","message":"Identifiant non valide"}');
  $r = $resultat->fetch_assoc();
  $resultat->free();
  $matiere = $r['matiere'];
  
  // Suppression
  if ( isset($_REQUEST['supprime']) )  {
    if ( $r['releve'] )
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
    exit( requete('notescolles',"DELETE FROM notescolles WHERE heure = $id",$mysqli) && requete('heurescolles',"DELETE FROM heurescolles WHERE id = $id",$mysqli)
          ? "{\"etat\":\"ok\",\"message\":\"Les $n notes de colles du <em>".format_date($r['date']).'</em> ont été supprimées."}'
          : "{\"etat\":\"nok\",\"message\":\"Les $n notes de colles du <em>".format_date($r['date']).'</em> n\'ont pas été supprimées. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');
  }

  // Modification
  if ( isset($_REQUEST['jour']) )  {
    $requete = array();
    
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
      // Validation de la durée (séance non relevée uniquement)
      if ( !$r['releve'] && ( ( $duree = call_user_func_array(function ($h,$m) { return intval($h)*60+intval($m); }, array_pad(explode('h',$_REQUEST['duree'] ?? ''),2,0)) ) > 0 ) && ( $duree != $r['duree'] ) )
        $requete[] = "duree = '$duree'";
      // Validation de la description
      if ( ( $description = trim(htmlspecialchars($_REQUEST['description'] ?? '')) ) && ( $description != $r['description'] ) )
        $requete[] = 'description = \''.$mysqli->real_escape_string(mb_strtoupper(mb_substr($description,0,1)).mb_substr($description,1)).'\'';
      // Écriture dans la table heurescolles
      if ( $requete )  {
        if ( requete('heurescolles','UPDATE heurescolles SET '.implode(', ',$requete)." WHERE id = $id",$mysqli) ) 
          exit($_SESSION['message'] = '{"etat":"ok","message":"La séance du <em>'.format_date($r['date']).'</em> a été modifiée.","reload":"2"}');
        exit('{"etat":"nok","message":"La séance du <em>'.format_date($r['date']).'</em> n\'a pas été modifiée. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');
      }
      else
        exit('{"etat":"nok","message":"La séance du <em>'.format_date($r['date']).'</em> n\'a pas été modifiée. Aucune modification demandée."}');
    }

    // Récupération des notes/commentaires déjà existantes pour cette heure-là
    $resultat = $mysqli->query("SELECT id, eleve, note, commentaire, semaine FROM notescolles WHERE heure = $id");
    $notesperso = $dejanotes = array();
    while ( $s = $resultat->fetch_assoc() )  {
      $notesperso[ $dejanotes[] = $s['eleve'] ] = $s;
      $semaine = $semaine ?? $s['semaine'];
    }
    $resultat->free();
    $resultat = $mysqli->query("SELECT colle FROM semaines WHERE id = $semaine");
    if ( $resultat->fetch_row()[0] == 1 )  {
      $resultat->free();
      $resultat = $mysqli->query("SELECT GROUP_CONCAT(eleve) FROM notescolles WHERE semaine = $semaine AND matiere = $matiere");
      $dejanotes = $resultat->fetch_row()[0];
    }
    else 
      $dejanotes = implode(',',$dejanotes);
    $resultat->free();
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
  
    // Si colle déjà relevée : modification des notes/commentaires déjà mis uniquement
    if ( $r['releve'] )  {
      foreach ( $notesperso as $eleve => $note )  {
        if ( in_array($newnote = $_REQUEST["e$eleve"] ?? '', $notes, true) && ( $newnote != $note['note'] ) )
          $requete_notes[] = "UPDATE notescolles SET note = '$newnote' WHERE id = ${note['id']}";
        if ( $note['commentaire'] != ( $newcomm = trim(htmlspecialchars( $_REQUEST["c$eleve"] ?? '')) ) )
          $requete_notes[] = 'UPDATE notescolles SET commentaire = \''.$mysqli->real_escape_string($newcomm)."' WHERE id = ${note['id']}";
      }
    }
    // Si colle non déjà relevée : modification de la durée possible, et
    // modification/ajout/suppression de notes possible 
    else  {
      // Validation de la durée
      if ( ( ( $duree = call_user_func_array(function ($h,$m) { return intval($h)*60+intval($m); }, array_pad(explode('h',$_REQUEST['duree'] ?? ''),2,0)) ) > 0 ) && ( $duree != $r['duree'] ) )
        $requete[] = "duree = '$duree', original = '$duree'";
      // Validation des notes déjà mises à modifier/supprimer
      foreach ( $notesperso as $eleve => $note )  {
        // Élève absent de la liste des notes (ou éventuellement note non valable)
        if ( !isset($_REQUEST["e$eleve"]) || !in_array($newnote = $_REQUEST["e$eleve"], $notes, true) )
          $requete_notes[] = "DELETE FROM notescolles WHERE id = ${note['id']}";
        else  {
          $modif = ( ( $newnote != $note['note'] ) ? "note = '$newnote'" : '' );
          if ( $note['commentaire'] != ( $newcomm = trim(htmlspecialchars( $_REQUEST["c$eleve"] ?? '' )) ) )
            $modif .= ( $modif ? ', ' : '' ). 'commentaire = \''.$mysqli->real_escape_string($newcomm).'\'';
          if ( $modif )
            $requete_notes[] = "UPDATE notescolles SET $modif WHERE id = ${note['id']}";
        }
      }
      // Récupération des élèves associés à la matière
      $resultat = $mysqli->query("SELECT GROUP_CONCAT(id) FROM utilisateurs WHERE autorisation = 2 AND FIND_IN_SET($matiere,matieres)");
      $s = $resultat->fetch_row();
      $resultat->free();
      // Insertion pour les élèves non déjà notés
      $elevesdispos = array_diff(explode(',',$s[0]),explode(',',$dejanotes));
      foreach ( $elevesdispos as $eleve )
        if ( isset($_REQUEST["e$eleve"]) && in_array($note = $_REQUEST["e$eleve"], $notes, true) )
          $requete_notes[] = "INSERT INTO notescolles (semaine,heure,eleve,colleur,matiere,note,commentaire)
                              VALUES ($semaine,$id,$eleve,${_SESSION['id']},$matiere,'$note','".$mysqli->real_escape_string(trim(htmlspecialchars( $_REQUEST["c$eleve"] ?? '' ))).'\')';
    }
    
    // Exécution
    $ok = true;
    if ( $requete )
      $message[] = ( ( $ok = requete('heurescolles','UPDATE heurescolles SET '.implode(', ',$requete)." WHERE id = $id",$mysqli) )
                     ? 'La colle du <em>'.format_date($r['date']).'</em> a été modifiée.'
                     :'La colle du <em>'.format_date($r['date']).'</em> n\'a pas été modifiée. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'».' );
    if ( $ok && $requete_notes )  {
      $nb_ok = 0;
      $nb_suppr = 0;
      foreach ( $requete_notes as $requete )  {
        if ( requete('notescolles',$requete,$mysqli) )  {
          $nb_ok += 1;
          if ( $requete[0] == 'D' )
            $nb_suppr += 1;
        }
        else  {
          $message[] = 'Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'».';
          $ok = false;
        }
      }
      // Suppression de l'heure si toutes les notes sont supprimées (et aucune ajoutée)
      if ( ( $nb_suppr == $nb_ok ) && ( $nb_suppr == count($notesperso) ) )  {
        if ( requete('heurescolles',"DELETE FROM heurescolles WHERE id = $id",$mysqli) )
          $message[] = 'La colle du <em>'.format_date($r['date']).'</em> a été supprimée.';
      }
      else 
        $message[] = ( ($nb_ok == 1) ? 'Une note a été modifiée/supprimée/ajoutée.' : "$nb_ok notes ont été modifiées/supprimées/ajoutées.");
    }
    
    // Reconstruction du message
    if ( !$message )
      exit('{"etat":"nok","message":"La colle du <em>'.format_date($r['date']).'</em> n\'a pas été modifiée. Aucune modification demandée."}');
    if ( $ok )
      exit($_SESSION['message'] = '{"etat":"ok","message":"'.implode('<br>',$message).'","reload":"2"}');
    exit('{"etat":"nok","message":"'.implode('<br>',$message).'"}');
  }
}

//////////////////////////////////////////////////////////////
// Ajout de notes de colles (accès colleurs et professeurs) //
//////////////////////////////////////////////////////////////
elseif ( ( $action == 'ajout-notescolles' ) && ( ( $autorisation == 3 ) || ( $autorisation == 5 ) ) && in_array($matiere = intval($_REQUEST['matiere'] ?? -1), explode(',',str_replace('c','',$_SESSION['matieres']))) && ( $jour = preg_filter('/(\d{2})\/(\d{2})\/(\d{4})/','$3-$2-$1',$_REQUEST['jour'] ?? '') ) )  {
  
  // Vérification de la durée
  if ( ( $duree = call_user_func_array(function ($h,$m) { return intval($h)*60+intval($m); }, array_pad(explode('h',$_REQUEST['duree'] ?? ''),2,0)) ) <= 0 )
    exit('{"etat":"nok","message":"La durée saisie ne peut pas être nulle."}');

  // Heure en séance de TD (pas de notes associées)
  if ( isset($_REQUEST['td']) )  {
    // Vérification que le jour est bien dans l'année
    $resultat = $mysqli->query("SELECT id FROM semaines WHERE debut <= '$jour' AND debut >= SUBDATE('$jour',7) ORDER BY debut DESC LIMIT 1");
    if ( !$resultat->num_rows )
      exit('{"etat":"nok","message":"Le jour choisi ne se trouve pas dans l\'année scolaire. S\'il s\'agit d\'une erreur, il faut peut-être contacter l\'administrateur."}');
    $resultat->free();
    // Vérification de la description
    if ( !($description = trim(htmlspecialchars($_REQUEST['description'] ?? ''))) )
      exit('{"etat":"nok","message":"Pour les séances de TD sans note, la description de la séance doit obligatoirement être non vide."}');
    // Écriture dans la table heurescolles
    if ( requete('heurescolles',"INSERT INTO heurescolles SET colleur = ${_SESSION['id']}, matiere = $matiere, jour = '$jour', rattrapage = '', duree = '$duree', description = '".$mysqli->real_escape_string(mb_strtoupper(mb_substr($description,0,1)).mb_substr($description,1))."', original = '$duree'",$mysqli) )
      exit($_SESSION['message'] = '{"etat":"ok","message":"La séance a été ajoutée.","reload":"2"}');
    exit('{"etat":"nok","message":"La séance n\'a pas été ajoutée. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');
  }
  
  // Heure de colle avec notes et commentaires éventuels
  // Vérification de l'identifiant de la semaine
  if ( !ctype_digit($sid = $_REQUEST['sid'] ?? '') )
    exit('{"etat":"nok","message":"Semaine non valide"}');
  $resultat = $mysqli->query("SELECT IF(colle=1,(SELECT GROUP_CONCAT(eleve) FROM notescolles WHERE semaine = semaines.id AND matiere = $matiere),''), colle FROM semaines WHERE id = $sid AND colle");
  if ( !$resultat->num_rows )
    exit('{"etat":"nok","message":"Semaine non valide"}');
  $dejanotes = $resultat->fetch_row()[0];
  $resultat->free();
  
  // Vérification que le jour est bien dans la semaine prévue
  $resultat = $mysqli->query("SELECT DATEDIFF('$jour',debut) FROM semaines WHERE id = $sid OR id = $sid+1");
  if ( ( ( $j = $resultat->fetch_row()[0] ) < 0 ) || ( ( ( $resultat->num_rows > 1 ) ? $resultat->fetch_row()[0] : $j-7 ) >= 0 ) )
    exit('{"etat":"nok","message":"La date saisie ne se trouve pas dans la semaine choisie."}');
  $resultat->free();

  // Récupération de la date de rattrapage si elle est donnée, vérification qu'elle est dans l'année
  if ( $rattrapage = preg_filter('/(\d{2})\/(\d{2})\/(\d{4})/','$3-$2-$1',$_REQUEST['rattrapage'] ?? '') )  {
    $resultat = $mysqli->query("SELECT id FROM semaines WHERE debut <= '$rattrapage' AND debut >= SUBDATE('$rattrapage',7) ORDER BY debut DESC LIMIT 1");
    if ( !$resultat->num_rows )
      exit('{"etat":"nok","message":"La date de rattrapage saisie ne se trouve pas dans l\'année scolaire. S\'il s\'agit d\'une erreur, il faut peut-être contacter l\'administrateur."}');
    $resultat->free();
  }
  else
    $rattrapage = '';
  
  // Écriture de l'heure dans la table heurescolles
  if ( !requete('heurescolles',"INSERT INTO heurescolles SET colleur = ${_SESSION['id']}, matiere = $matiere, jour = '$jour', rattrapage = '$rattrapage', duree = '$duree', description = '', original = '$duree'",$mysqli) )
    exit('{"etat":"nok","message":"La colle n\'a pas été ajoutée. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');
  $heure = $mysqli->insert_id;
  
  // Récupération des élèves associés à la matière
  $resultat = $mysqli->query("SELECT GROUP_CONCAT(id) FROM utilisateurs WHERE autorisation = 2 AND FIND_IN_SET($matiere,matieres)");
  $eids = $resultat->fetch_row()[0];
  $resultat->free();
  $elevesdispos = array_diff(explode(',',$eids),explode(',',$dejanotes));
  $notes = array('0','1','2','3','4','5','6','7','8','9','10','11','12','13','14','15','16','17','18','19','20','0,5','1,5','2,5','3,5','4,5','5,5','6,5','7,5','8,5','9,5','10,5','11,5','12,5','13,5','14,5','15,5','16,5','17,5','18,5','19,5','abs','nn');

  // Insertion pour les élèves non déjà notés
  $requete = array();
  foreach ( $elevesdispos as $eleve )
    if ( isset($_REQUEST["e$eleve"]) && in_array($note = $_REQUEST["e$eleve"], $notes, true) )
      $requete[] = "($sid,$heure,$eleve,${_SESSION['id']},$matiere,'$note','".$mysqli->real_escape_string(trim(htmlspecialchars( $_REQUEST["c$eleve"] ?? '' ))).'\')';
  if ( !$requete )  {
    requete('heurescolles',"DELETE FROM heurescolles WHERE id=$heure",$mysqli);
    exit('{"etat":"nok","message":"La colle n\'a pas été ajoutée, car aucune note valable n\'a été saisie."}');
  }
  // Écriture des notes
  if ( requete('notescolles','INSERT INTO notescolles (semaine,heure,eleve,colleur,matiere,note,commentaire) VALUES '.implode(',',$requete),$mysqli) )  {
    $mysqli->query("UPDATE matieres SET notescolles = 1 WHERE id = $matiere");
    if ( $mysqli->affected_rows )
      $mysqli->query("UPDATE utilisateurs SET menuelements='' WHERE autorisation = 2 AND FIND_IN_SET($matiere,menumatieres)");
    exit($_SESSION['message'] = '{"etat":"ok","message":"'.count($requete).' notes ont été ajoutées.","reload":"2"}');
  }
  exit('{"etat":"nok","message":"Toutes les notes saisies n\'ont pas été ajoutées. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');
}

/////////////////////////////////////////////////////////////////
// Relève des notes de colles (accès administrateurs et lycée) //
/////////////////////////////////////////////////////////////////
elseif ( ( $action == 'releve-colles' ) && ( ( $autorisation == 4 ) || $_SESSION['admin'] ) && ( $datemax = preg_filter('/(\d{2})\/(\d{2})\/(\d{4})/','$3-$2-$1',$_REQUEST['datemax'] ?? '') ) )  {

  // Vérification qu'il y a des colles à relever
  $resultat = $mysqli->query("SELECT * FROM heurescolles WHERE releve=0 AND jour <= '$datemax'");
  if ( !$resultat->num_rows )
    exit("{\"etat\":\"nok\",\"message\":\"Il n'y a pas d'heure de colle à relever avant le $datemax.\"}");
  if ( requete('heurescolles',"UPDATE heurescolles SET releve=CURDATE() WHERE releve=0 AND jour <= '$datemax'", $mysqli) )
    exit($_SESSION['message'] = '{"etat":"ok","message":"De nouvelles heures de colles ont été relevées. La relève apparaît dans le tableau ci-dessous.","reload":"1"}');
  exit('{"etat":"nok","message":"La relève n\'a pas été réalisée. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');
}

////////////////////////////////////////////////////////////////////////
// Modification d'une durée de colle (accès administrateurs et lycée) //
////////////////////////////////////////////////////////////////////////
elseif ( ( $action == 'dureecolles' ) && ( ( $autorisation == 4 ) || $_SESSION['admin'] ) && ctype_digit($id = $_REQUEST['id'] ?? '') && ( 'duree' == $_REQUEST['champ'] ?? '' ) )  {

  // Vérification que l'identifiant est valide
  $resultat = $mysqli->query("SELECT DATE_FORMAT(jour,'%w%Y%m%e') AS date, duree FROM heurescolles WHERE id = $id AND releve = 0");
  if ( !$resultat->num_rows )
    exit('{"etat":"nok","message":"Identifiant non valide"}');
  $r = $resultat->fetch_assoc();
  $resultat->free();

  // Modification d'un champ unique : durée de colle
  if ( ( ( $duree = call_user_func_array(function ($h,$m) { return intval($h)*60+intval($m); }, array_pad(explode('h',$_REQUEST['val'] ?? ''),2,0)) ) <= 0 ) || ( $duree == $r['duree'] ) ) 
    exit('{"etat":"nok","message":"La durée de la colle du <em>'.format_date($r['date']).'</em> n\'a pas été modifiée, aucune modification demandée."}');
  if ( requete('heurescolles',"UPDATE heurescolles SET duree = '$duree' WHERE id = $id",$mysqli) )
    exit('{"etat":"ok","message":"La durée de la colle du <em>'.format_date($r['date']).'</em> a été modifiée."}');
  exit('{"etat":"nok","message":"La durée de la colle du <em>'.format_date($r['date']).'</em> n\'a pas été modifiée. Erreur MySQL n°'.$mysqli->errno.', «'.$mysqli->error.'»."}');

}

// Sans action
exit('{"etat":"nok","message":"Aucune action effectuée"}');
?>
