<?php
// Sécurité : ne doit pas être exécuté s'il n'est pas lancé par un script autorisé
if ( !defined('OK') )  exit();

////////////////////////////////////////////////////////////////////////////////
//////////////////////////// Création de la session ////////////////////////////
////////////////////////////////////////////////////////////////////////////////
session_name('CDP_SESSION');
session_set_cookie_params(array('lifetime'=>0,'path'=>$chemin,'domain'=>$domaine,'secure'=>true,'httponly'=>false,'samesite'=>'Lax'));
session_start();
// Niveau d'autorisation
//  * 0 = non connecté
//  * 1 = compte invité
//  * 2 = élève
//  * 3 = colleur
//  * 4 = lycée
//  * 5 = professeur
// Gestion des protections : voir la fonction acces ci-dessous
$message = '';
// Gestion des utilisateurs connectés
if ( isset($_SESSION['chemin']) && ( $_SESSION['chemin'] == $chemin ) )  {
  // Passage en connexion light si timeout mais connexion permanente
  if ( ( $_SESSION['time'] < time() ) && !$_SESSION['light'] && $_SESSION['permconn'] )
    enregistre_session(false,true);
  // Passage en connexion normale si reconnexion sur une connexion light
  elseif ( $_SESSION['light'] && isset($_REQUEST['motdepasse']) )  {
    $mysqli = connectsql();
    // Récupération du compte dans la base de données
    $resultat = $mysqli->query('SELECT timeout FROM utilisateurs WHERE mdp = \''.sha1($mdp.$_REQUEST['motdepasse'])."' AND id = ${_SESSION['id']}");
    $mysqli->close();
    if ( $resultat->num_rows )  {
      $r = $resultat->fetch_row();
      $resultat->free();
      enregistre_session(false,false,$r[0]);
      // Si paramètre "connexion", reconnexion sans autre demande (par login.php),
      // suivie d'un rechargement immédiat : terminaison et $_SESSION['message']
      if ( isset($_REQUEST['connexion']) )  {
        // Si on vient de docs.php, connexion préalable à l'envoi de fichier
        // Modification du timeout pour autoriser un envoi sur une durée de 1h
        if ( substr($_SERVER['PHP_SELF'],-8) == 'docs.php' )  {
          $_SESSION['time'] = max($_SESSION['time'],time()+3600);
          exit('{"etat":"ok","message":"Envoi du document en cours..."}');
        }
        exit($_SESSION['message'] = '{"etat":"ok","message":"Connexion réussie","reload":"2"}');
      }
    }
    else
      exit('{"etat":"'.(isset($_REQUEST['connexion'])?'':'mdp').'nok","message":"Mauvais mot de passe"}');
  }
  // Déconnexion automatique si Timeout ou changement de UserAgent
  elseif ( ( $_SESSION['time'] < time() ) || ( $_SESSION['client'] != ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ) )  {
    suppression_session($_SESSION['time'] < time() ? 2 : 3);
    // Pour la suite du script
    $message = 'Vous devez vous connecter à nouveau, suite à une longue durée d\'inactivité.';
    $_SESSION['autorisation'] = 0;
  }
  // Tout est ok : session valide pendant timeout
  else  {
    $_SESSION['time'] = time() + $_SESSION['timeout'];
    // Si on vient d'une connexion automatique de l'interface globale
    if ( isset($_SESSION['recents']) && ( $_SESSION['recents'] < 0 ) )  {
      $mysqli2 = connectsql(true);
      $resultat = $mysqli2->query("SELECT COUNT(id) FROM recents WHERE publi > '${_SESSION['lastconn']}' OR maj > '${_SESSION['lastconn']}'");
      $_SESSION['recents'] = $resultat->fetch_row()[0];
      $resultat->free();
      $mysqli2->close();
    }
  }
}
// Connexion complète (login et mdp, script ajax.php demandé)
elseif ( isset($_REQUEST['motdepasse']) && ( $login = trim($_REQUEST['login'] ?? '') ) )  {
  // Pas de connexion a priori
  $_SESSION['autorisation'] = 0;
  // Récupération du compte dans la base de données
  $mysqli = connectsql();
  $resultat = $mysqli->query('SELECT * FROM utilisateurs WHERE mdp = \''.sha1($mdp.$_REQUEST['motdepasse']).'\'');
  while ( $r = $resultat->fetch_assoc() )
    if ( ( $r['login'] == $login ) || ( $r['mail'] == strtolower($login) ) )  {
      // Pas de connexion permanente si ce n'est pas coché (mais on ne modifie pas la
      // base : on ne supprime pas celles qui pourraient exister sur d'autres appareils)
      if ( !isset($_REQUEST['permconn']) )
        $r['permconn'] = '';
      // Génération du token de connexion automatique si demandé et s'il n'existe pas déjà
      elseif ( !$r['permconn'] )  {
        $permconn = '';
        for ( $i = 0; $i < 10; $i++ )
          $permconn .= '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ'[random_int(0,61)];
        $mysqli2 = connectsql(true);
        $mysqli2->query("UPDATE utilisateurs SET permconn = '$permconn' WHERE id = ${r['id']}");
        $mysqli2->close();
        $r['permconn'] = $permconn;
      }
      // Enregistrement de la session et écriture du cookie pour connexion light
      enregistre_session($r,false);
      if ( $r['permconn'] )
        setcookie('CDP_SESSION_PERM',$r['permconn'],array('expires'=>time()+31536000,'path'=>$chemin,'domain'=>$domaine,'secure'=>true,'httponly'=>false,'samesite'=>'Lax'));
      break;
    }
  $resultat->free();
  $mysqli->close();
  if ( !$_SESSION['autorisation'] )
    exit('{"etat":"nok","message":"Mauvais couple identifiant/mot de passe"}');
  // Si paramètre "connexion", connexion initiale (bouton "connexion" ou login.php),
  // suivie d'un rechargement immédiat : terminaison et $_SESSION['message']
  if ( isset($_REQUEST['connexion']) )
    exit($_SESSION['message'] = '{"etat":"ok","message":"Connexion réussie","reload":"2"}');
}
// Connexion light automatique par cookie
elseif ( isset($_COOKIE['CDP_SESSION_PERM']) && preg_match('/^\w{10}$/',$_COOKIE['CDP_SESSION_PERM']) )  {
  $mysqli = connectsql();
  // Récupération du compte dans la base de données
  $resultat = $mysqli->query("SELECT * FROM utilisateurs WHERE mdp > '0' AND permconn = '${_COOKIE['CDP_SESSION_PERM']}'");
  $mysqli->close();
  if ( $resultat->num_rows )  {
    enregistre_session($resultat->fetch_assoc(),true);
    $resultat->free();
  }
  // Suppression du cookie s'il ne correspond pas à un compte
  else 
    setcookie('CDP_SESSION_PERM','',array('expires'=>time()-3600,'path'=>$chemin,'domain'=>$domaine,'secure'=>true,'httponly'=>false,'samesite'=>'Lax'));
}
$autorisation = $_SESSION['autorisation'] ?? 0;
// Destruction du cookie de session si non connecté
if ( !$autorisation )
  setcookie('CDP_SESSION','',array('expires'=>time()-3600,'path'=>$chemin,'domain'=>$domaine,'secure'=>true,'httponly'=>false,'samesite'=>'Lax'));

////////////////////////////////////////////////////////////////////////////////
//////////////////////////// Gestion de la session /////////////////////////////
////////////////////////////////////////////////////////////////////////////////

// Fonction de connexion à la base MySQL
// $interfaceglobale permet d'écrire dans la base de données globales, pour les
// mises à jour d'adresse électronique/mot de passe, si configuré dans config.php.
function connectsql($ecriture=false,$interfaceglobale=false)  {
  if ( $interfaceglobale )  {
    // Le include est dans une fonction pour éviter la réécriture des variables
    include("${interfaceglobale}config.php");
    $mysqli = new mysqli($serveur,( $ecriture ) ? $base.'-adm' : $base, $mdp, $base);
  }
  else
    $mysqli = new mysqli($GLOBALS['serveur'],( $ecriture ) ? $GLOBALS['base'].'-adm' : $GLOBALS['base'], $GLOBALS['mdp'], $GLOBALS['base']);
  $mysqli->set_charset('utf8');
  // Gestion des timezones hors métropole 
  // * date_default_timezone('[le TZ code]') a été exécuté dans config.php
  // * les TZ sont à installer dans MySQL : faire une fois pour toutes en console
  // mysql_tzinfo_to_sql /usr/share/zoneinfo | mysql -u root -p mysql 
  if ( substr(date_default_timezone_get(),0,6) != 'Europe' )
    $mysqli->query('SET time_zone="'.date_default_timezone_get().'";');
  return $mysqli;
}

// Fonction d'écriture des connexions
// $connexion = 1 pour connexion normale, 2 pour connexion light par cookie,
//              3 pour reconnexion normale, 4 pour reconnexion light
//              5 pour déconnexion, 6 pour déconnexion définitive (compte supprimé)
//              7 déconnexion pour timeout, 8 déconnexion pour raison de sécurité
function logconnect($connexion)  {
  if ( is_dir('sauvegarde') && is_executable('sauvegarde') && is_writable('sauvegarde') )  {
    if ( !file_exists($fichier = 'sauvegarde/connexion.'.date('Y-m').'.php') )  {
      $f = fopen($fichier,'wb');
      fwrite($f, "<?php exit(); ?>\n\n");
    }
    else
      $f = fopen($fichier,'ab');
    switch ( $connexion )  {
      case 1: fwrite($f, 'Le '.date('d/m/Y à H:i:s').", connexion de ${_SESSION['login']}\n"); break;
      case 2: fwrite($f, 'Le '.date('d/m/Y à H:i:s').", connexion light par cookie de ${_SESSION['login']}\n"); break;
      case 3: fwrite($f, 'Le '.date('d/m/Y à H:i:s').", reconnexion normale de ${_SESSION['login']}\n"); break;
      case 4: fwrite($f, 'Le '.date('d/m/Y à H:i:s').", reconnexion light de ${_SESSION['login']}\n"); break;
      case 5: fwrite($f, 'Le '.date('d/m/Y à H:i:s').", déconnexion de ${_SESSION['login']}\n"); break;
      case 6: fwrite($f, 'Le '.date('d/m/Y à H:i:s').", déconnexion (utilisateur supprimé) de ${_SESSION['login']}\n"); break;
      case 7: fwrite($f, 'Le '.date('d/m/Y à H:i:s').", déconnexion (timeout) de ${_SESSION['login']}\n"); break;
      case 8: fwrite($f, 'Le '.date('d/m/Y à H:i:s').", déconnexion (sécurité) de ${_SESSION['login']}\n"); break;
    }
    fclose($f);
  }
}

// Fonction d'enregistrement de session : remplissage de la variable $_SESSION et log
// $r contient les données de l'utilisateur pour une nouvelle session, ou false
// pour une simple mise à jour de $_SESSION['light'], $_SESSION['timeout'] et $_SESSION['time']
// $light : true si la connexion est obtenue par cookie et ne permet que la lecture
// $timeout : utile dans le cas du passage de connexion normale à connexion light uniquement
// Cette fonction est utilisée uniquement dans ajax.php et fonctions.php
function enregistre_session($r,$light,$timeout=0)  {
  // Nouvelle session
  if ( $r )  {
    // Interdiction de garder son identifiant de session
    session_regenerate_id(true);
    $_SESSION = array();
    // Interdiction de pouvoir se connecter aux autres site sur le même serveur
    $_SESSION['chemin'] = $GLOBALS['chemin'];
    // Pour vérification aux connexions ultérieures
    $_SESSION['client'] = ( $_SERVER['HTTP_USER_AGENT'] ?? '' );
    $_SESSION['login'] = $r['login'];
    $_SESSION['id'] = $r['id'];
    $_SESSION['permconn'] = $r['permconn'];
    $_SESSION['lastconn'] = $r['lastconn'];
    // Mise à jour de dernière connexion et des éléments du menu (a priori non nécessaire)
    $mysqli = connectsql(true);
    $mysqli->query("UPDATE utilisateurs SET menuelements = '', lastconn = NOW() WHERE id = ${r['id']}");
    // Récupération du nombre d'éléments récents
    $resultat = $mysqli->query("SELECT COUNT(id) FROM recents WHERE publi > '${r['lastconn']}' OR maj > '${r['lastconn']}'");
    $_SESSION['recents'] = $resultat->fetch_row()[0];
    $resultat->free();
    $mysqli->close();
    // Autorisations
    $_SESSION['light'] = $light;
    $_SESSION['autorisation'] = $r['autorisation']%10;
    $_SESSION['admin'] = ( $r['autorisation'] > 10 );
    $_SESSION['mode_lecture'] = 0;
    $_SESSION['matieres'] = $r['matieres'];
    // Temps de session : depuis la base si connexion normale, 1 jour si light
    $_SESSION['timeout'] = ( $light ) ? 86400 : ( $r['timeout']?:900 );
    $_SESSION['time'] = time() + $_SESSION['timeout'];
    // Pour sécurisation des requêtes AJAX
    $_SESSION['csrf-token'] = $_REQUEST['csrf-token'] ?? bin2hex(random_bytes(32));
    // Si interface globale, vérification
    $_SESSION['compteglobal'] = false;
    if ( ( $r['autorisation'] > 1 ) && $GLOBALS['interfaceglobale'] )  {
      $mysqli = connectsql(false,$GLOBALS['interfaceglobale']);
      // Identifiant global de l'utilisateur = 1000*idCahier+idUtilisateur
      // On cherche uniquement un compte correspondant à au moins deux connexions
      $resultat = $mysqli->query("SELECT id FROM comptes 
                                  WHERE LOCATE(',',connexions) AND FIND_IN_SET( (SELECT id FROM cahiers WHERE rep = TRIM(BOTH '/' FROM '${GLOBALS['chemin']}'))*1000+${r['id']}, connexions)");
      if ( $resultat->num_rows )  {
        $_SESSION['compteglobal'] = $resultat->fetch_row()[0];
        $resultat->free();
      }
      $mysqli->close();
    }
    // Écriture de la connexion dans le fichier de log (voir commentaires de logconnect)
    logconnect($light ? 1 : 2);
  }
  // Mise à jour de session
  else  {
    $_SESSION['light'] = $light;
    $_SESSION['timeout'] = ( $light ) ? 86400 : ( $timeout?:900 );
    $_SESSION['time'] = time() + $_SESSION['timeout'];
    // Écriture de la connexion dans le fichier de log (voir commentaires de logconnect)
    logconnect($light ? 3 : 4);
  }
}

// Fonction de suppression de session
// Cette fonction est utilisée uniquement dans ajax.php et fonctions.php
// pour la déconnexion complète (automatique ou demandée).
// $cause sert pour le journal : 0->demandée, 2->timeout, 3->sécurité
function suppression_session($cause=0)  {
  // Écriture de la déconnexion dans le fichier de log, sauf si la session a
  // été perdue parce qu'effacée du serveur
  if ( isset($_SESSION['login']) )  {
    logconnect(5 + $cause);
    // Suppression des données de session et des cookies
    $_SESSION = array();
    session_regenerate_id(true);
    setcookie('CDP_SESSION','',array('expires'=>time()-3600,'path'=>$GLOBALS['chemin'],'domain'=>$GLOBALS['domaine'],'secure'=>true,'httponly'=>false,'samesite'=>'Lax'));
    setcookie('CDP_SESSION_PERM','',array('expires'=>time()-3600,'path'=>$GLOBALS['chemin'],'domain'=>$GLOBALS['domaine'],'secure'=>true,'httponly'=>false,'samesite'=>'Lax'));
  }
}

// Fonction de vérification de la qualité light ou non de la connexion
// Renvoie true si la connexion est complète, false si elle est light.
// Les connexions permanentes le sont par connexion par cookie, sans taper
// son mot de passe systématiquement. Pour les modifications, il faut se
// connecter à nouveau. Cette fonction n'est utilisée que dans ajax.php.
function connexionlight()  {
  if ( $_SESSION['light'] )
    exit('{"etat":"mdp"}');
  return true;
}

////////////////////////////////////////////////////////////////////////////////
////////////////////// Mise à jour de la base de données ///////////////////////
////////////////////////////////////////////////////////////////////////////////

// Fonction d'envoi de requêtes MySQL et d'enregistrement
//  * sauvegarde une fois par mois la table complète
//  * enregistre la requête
//  * exécute la requête
//  * renvoie le résultat de l'exécution
function requete($table,$requete,$mysqli)  {
  if ( is_dir($rep = 'sauvegarde') && is_executable('sauvegarde') && is_writable('sauvegarde') )  {
    // Sauvegarde de la table complète une seule fois par mois
    $mois = date('Y-m');
    $heure = date('d/m/Y à H:i:s');
    if ( !file_exists("$rep/$table.$mois.php") )  {
      $s = <<<FIN
  <?php exit(); ?>
-- Sauvegarde complète de la table $table le $heure
TRUNCATE `$table`; 
FIN;
      $resultat = $mysqli->query("SHOW COLUMNS FROM `$table`");
      $s1 = "INSERT INTO $table (";
      while ( $r = $resultat->fetch_row() )
        $s1 .= "`${r[0]}`,";
      $s1 = substr($s1,0,-1).') VALUES';
      $resultat->free();
      // Récupération des données
      $resultat = $mysqli->query("SELECT * FROM `$table`");
      if ( $resultat->num_rows )  {
        while ( $r = $resultat->fetch_row() )
          $s1 .= "\n  ('".  str_replace('SEPARATEUR','\',\'',addslashes(implode('SEPARATEUR',$r)))  .'\'),';
        $s1 = substr($s1,0,-1).';';
        $resultat->free();
      }
      else
        $s1 = '-- Table vide !';
      $fichier = fopen("$rep/$table.$mois.php",'wb');
      fwrite($fichier, "$s\n$s1\n");
    }
    else
      $fichier = fopen("$rep/$table.$mois.php",'ab');
    // Éxécution de la requête
    $resultat = $mysqli->query($requete);
    // Sauvegarde systématique de la requête
    $insert = ( $mysqli->insert_id ) ? ' (identifiant '.$mysqli->insert_id.')' : '';
    $erreur = ( $mysqli->errno ) ? ' (erreur '.$mysqli->errno.')' : '';
    if ( isset($_SESSION['login'])  )
      fwrite($fichier, "\n-- Requête de ${_SESSION['login']} (${_SERVER['REMOTE_ADDR']}) le $heure\n$requete; -- ".$mysqli->affected_rows." ligne(s) affectée(s)$insert$erreur\n");
    else  {
      $login = ( isset($GLOBALS['utilisateur']) ) ? $GLOBALS['utilisateur']['login'] : $GLOBALS['login'];
      fwrite($fichier, "\n-- Requête de $login (${_SERVER['REMOTE_ADDR']}) le $heure\n$requete; -- ".$mysqli->affected_rows." ligne(s) affectée(s)$insert$erreur\n");
    }
    fclose($fichier);
  }
  return $resultat;
}

// Fonction de mise à jour des données de matières suite à l'ajout/suppression
// ou modification de protection à un ou des documents.
// $mid : matière à modifier
// $majmenu : forçage de la mise à jour du menu si true. (false par défaut)
// $verif : forçage de la vérification des documents non cachés si true,
//          marque docs = 1 si false (false par défaut)
// Remarque : les dates de disponibilités ne sont pas gérées
function majmatiere($mysqli, $mid, $majmenu = false, $ajout = false)  {
  $val = ( $ajout ) ? '1' : "IF( ( SELECT id FROM docs WHERE matiere = $mid AND protection < 32 LIMIT 1 ), 1,0)";
  $resultat = $mysqli->query("UPDATE matieres SET docs = $val WHERE id = $mid");
  if ( $majmenu || $mysqli->affected_rows )
    return $mysqli->query("UPDATE utilisateurs SET menuelements='' WHERE FIND_IN_SET($mid,menumatieres)");
  return $resultat;
}

// Fonction de mise à jour des informations récentes
// $type : 1->informations, 2->programmes de colles, 3->documents,
//         4->agenda, -- V11 : 5->transfert
// $id : celui de l'information/le programme de colles/le document/
//       l'événement/le transfert
// $prop : true si ajout, tableau si mise à jour, vide si suppression
//         si tableau, contient des valeurs parmi 
//         matiere (ou 0), titre, lien, texte, protection, publi
//         les chaines titre, lien et texte doivent être échappées
//         la valeur publi permet de positionner des infos dans le futur
// $maj : true si modification de la date de mise à jour (champ maj)
// $rss : true si modfication des flux rss, par défaut. Utile uniquement 
//        pour la fonction recents() qui prend plusieurs identifiants et
//        doit mettre à jour les flux rss une seule fois.
function recent($mysqli,$type,$id,$prop=array(),$maj=false,$rss=true)  {
  // Suppression de la base
  if ( !$prop )  {
    // Récupération des anciennes propriétés ; rien à faire si n'existe pas
    $resultat = $mysqli->query("SELECT matiere, protection FROM recents WHERE id = $id AND type = $type");
    if ( !$resultat->num_rows )
      return true;
    $prop = $resultat->fetch_assoc();
    $resultat->free();
    requete('recents',"DELETE FROM recents WHERE id = $id AND type = $type",$mysqli);
  }
  // Mise à jour
  elseif ( is_array($prop) && ( $resultat = $mysqli->query("SELECT matiere, titre, lien, texte, protection FROM recents WHERE id = $id AND type = $type") ) && $resultat->num_rows )  {
    $anciennesprop = $resultat->fetch_assoc();
    $resultat->free();
    // Construction de la requête et exécution
    $requete = '';
    if ( $maj )
      $requete = ', maj = GREATEST(NOW(),publi)';
    elseif ( isset($prop['publi']) )  {
      $requete = ", publi = '${prop['publi']}', maj = 0";
      unset($prop['publi']);
    }
    elseif ( isset($prop['protection']) )  {
      if ( $anciennesprop['protection'] == 32 )
        $requete = ', publi = GREATEST(NOW(),publi), maj = 0';
      elseif ( $prop['protection'] == 32 )
        $requete = ', publi = 0, maj = 0';
    }
    foreach ($prop as $champ=>$val)
      $requete .= is_numeric($val) ? ", $champ = $val" : ", $champ = '$val'";
    $requete = substr($requete,1);
    requete('recents',"UPDATE recents SET $requete WHERE id = $id AND type = $type",$mysqli);
  }
  // Ajout
  else  {
    // Suppression de $prop, cas où l'élément à modifier n'a pas été trouvé
    $prop = array();
    if ( $type == 1 )
      $resultat = $mysqli->query("SELECT matiere, CONCAT('.?',IF(matiere=0,'',CONCAT(m.cle,'/')),p.cle) AS lien, texte, i.protection, dispo,
                                         CONCAT( IF(LENGTH(i.titre),i.titre,'Information'), IF(p.id>1,CONCAT(' [',IF(matiere=0,'',CONCAT(m.nom,'/')),p.nom,']'),'') ) AS titre
                                  FROM infos AS i LEFT JOIN pages AS p ON page=p.id LEFT JOIN matieres AS m ON matiere=m.id WHERE i.id = $id");
    elseif ( $type == 2 )
      $resultat = $mysqli->query("SELECT matiere, CONCAT('Colles du ',DATE_FORMAT(debut,'%e/%m'),' en ',nom) AS titre, dispo,
                                         CONCAT('progcolles?',cle,'&amp;n=',semaine) AS lien, texte, IF(cache,32,progcolles_protection) AS protection
                                  FROM progcolles LEFT JOIN matieres ON matiere=matieres.id LEFT JOIN semaines ON semaine=semaines.id
                                  WHERE progcolles.id = $id");
    elseif ( $type == 3 )
      $resultat = $mysqli->query("SELECT d.matiere, d.nom AS titre, 'download?id=$id' AS lien, d.protection, dispo,
                                         CONCAT(ext,'|',taille,'|',d.parent,'|',GROUP_CONCAT( r.nom ORDER BY FIND_IN_SET(r.id,d.parents) SEPARATOR '/' )) AS texte
                                  FROM docs AS d LEFT JOIN reps AS r ON FIND_IN_SET(r.id,d.parents) WHERE d.id = $id");
    elseif ( $type == 4 )
      $resultat = $mysqli->query("SELECT a.matiere, CONCAT(SUBSTRING(debut,9,2),'/',SUBSTRING(debut,6,2),' - ',t.nom,IF(a.matiere>0, CONCAT(' en ',m.nom),'')) AS titre, dispo,
                                         CONCAT('agenda?mois=',SUBSTRING(debut,3,2),SUBSTRING(debut,6,2)) AS lien, texte, protection
                                  FROM agenda AS a LEFT JOIN matieres AS m ON matiere = m.id LEFT JOIN `agenda-types` AS t ON type = t.id WHERE a.id = $id");
    $prop = array_map(array($mysqli,'real_escape_string'),$resultat->fetch_assoc());
    $resultat->free();
    requete('recents',"INSERT INTO recents SET id=$id, type=$type, publi = GREATEST(NOW(),'${prop['dispo']}'), matiere = ${prop['matiere']}, titre = '${prop['titre']}', lien = '${prop['lien']}', texte = '${prop['texte']}', protection = ${prop['protection']}",$mysqli);
  }
  // Sélection des matières concernées
  $matieres = ( !isset($prop['matiere']) || !isset($anciennesprop['matiere']) ) ? array( $prop['matiere'] ?? $anciennesprop['matiere'] ) : array( $prop['matiere'], $anciennesprop['matiere'] );
  // Sélection des autorisations concernées
  // Si $prop['protection'] seule existe, c'est un ajout ou la modification d'une autre propriété
  // Si $ancienne['protection'] seule existe, c'est une suppression
  // Dans les deux cas, on modifie les flux correspondant, aucun si 32.
  // Retour nécessaire en cas d'utilisation de recents() : $matieres, $protectionavant, $protectionaprès (ou -1 si une seule protection)
  if ( !isset($prop['protection']) || !isset($anciennesprop['protection']) )  {
    if ( ( $protection = $prop['protection'] ?? $anciennesprop['protection'] ) == 32 )
      return true;
    if ( $rss )
      rss($mysqli,$matieres,$protection);
    return array($matieres, $protection, -1);
  }
  // Si c'est une modification de protection, les deux existent
  if ( $rss )
    rss($mysqli,$matieres,$prop['protection'],$anciennesprop['protection']);
  return array($matieres, $prop['protection'], $anciennesprop['protection']);
}

// Fonction de mise à jour multiple des informations récentes
// Arguments identiques à la fonction recent(), à part $ids : tableau
// contenant les identifiants des ressources concernées
function recents($mysqli,$type,$ids,$prop=array(),$maj=false)  {
  $matieres = array();
  $autorisations = 0;
  foreach ( $ids as $id )  {
    // Retour de recent : $matiere, $protectionavant ou $protection après
    if ( is_array($resultat = recent($mysqli,$type,$id,$prop,$maj,false)) )  {
      $matieres = array_unique(array_merge($matieres,$resultat[0]));
      // Plus rien à faire si $autorisations = 32 : on doit tout générer
      if ( $autorisations < 32 )  {
        // Si une des deux protections est nulle, on doit tout générer
        if ( ( !$resultat[1] ) || ( !$resultat[2] ) )
          $autorisations = 32;
        // Si une seule protection donnée : on ajoute les autorisations
        elseif ( $resultat[2] < 0 )
          $autorisations = $autorisations | ( 32 - $resultat[1] );
        // Si deux protections données : il faut faire la différence entre les deux 
        // (par ou exclusif), ce sont les autorisations concernées
        else
          $autorisations = $autorisations | (($resultat[1]-1) ^ ($resultat[2]-1));
      }
    }
  }
  // Lancement unique de la mise à jour des flux rss
  return ( $matieres ) ? rss($mysqli,$matieres,32-$autorisations) : true;
}

// Fonction de mise à jour des flux RSS
// $matieres doit être soit zéro (toutes matières) ou un identifiant de matière
// $protection correspond à la protection de la nouveauté/mise à jour
// Si $protection est négatif, il faut remettre à jour le flux général uniquement
// Le cas $protection = 32 et $ancienne protection = -1 n'a aucun sens (contenu caché)
// et ne doit pas arriver.
function rss($mysqli,$matieres,$protection = -1,$ancienneprotection = -1)  {
  if ( $protection < 0 )
    $combinaisons = array('0|toutes');
  else  {
    $requete = ( !is_array($matieres) ? "FIND_IN_SET($matieres,matieres)" : '( '. implode(' OR ',array_map(function($m) { return "FIND_IN_SET($m,matieres)"; }, $matieres)) .' )' );
    // Si seule, $protection est définie comme expliqué avant la fonction acces
    // et 32-$protection donne les autorisations concernées.
    // Si deux protections (avant et après), on détermine l'ensemble des comptes
    // voyant ou ayant vu le contenu.
    $autorisations = ( ( $ancienneprotection < 0 ) ? ( 32 - $protection ) : ( 32 - $protection ) | ( 32 - $ancienneprotection ) );
    if ( $autorisations < 32 )  {
      // Il faut retourner la chaîne et la décaler d'un cran, avant de récupérer
      // les indices des 1 présents.
      if ( $autorisations = array_keys(str_split('0'.strrev(decbin( $autorisations ))),1) )
        $requete .= ' AND ( '. implode(' OR ',array_map(function($a) { return "autorisation = $a"; }, $autorisations) ) .' )';
    }
    // Si protection (avant ou après) est nulle, il faut ajouter la combinaison
    // avec toutes les matières
    $combinaisons = ( $protection && $ancienneprotection ) ? array() : array('0|toutes');

    // Récupération de toutes les combinaisons autorisation-matières possibles
    $resultat = $mysqli->query("SELECT CONCAT(autorisation%10,'|',matieres) FROM utilisateurs WHERE $requete");
    if ( $resultat->num_rows )  {
      while ( $r = $resultat->fetch_row() )
        $combinaisons[] = $r[0];
      $resultat->free();
    }
    if ( !($combinaisons = array_unique($combinaisons)) )
      return true;
  }
    
  // Préambule du flux RSS - Titre du flux : titre du site
  $resultat = $mysqli->query('SELECT titre FROM pages WHERE id = 1');
  $titre = $resultat->fetch_row()[0];
  $resultat->free();
  $d = date(DATE_RSS);
  $site = "https://${GLOBALS['domaine']}${GLOBALS['chemin']}";
  $preambule = <<<FIN
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
  <channel>
    <title>$titre</title>
    <atom:link href="${site}REP/rss.xml" rel="self" type="application/rss+xml" />
    <link>$site</link>
    <description>$titre - Flux RSS</description>
    <lastBuildDate>$d</lastBuildDate>
    <language>fr-FR</language>


FIN;

  // Log
  $mois = date('Y-m');
  $heure = date('d/m/Y à H:i:s');
  if ( file_exists("rss/log.$mois.php") )
    $fichierlog = fopen("rss/log.$mois.php",'ab');
  else  {
    $fichierlog = fopen("rss/log.$mois.php",'wb');
    fwrite($fichierlog,'<?php exit(); ?>');
  }
  fwrite($fichierlog, "\n-- Génération le $heure -- \nAppel avec \$matieres = ".json_encode($matieres).", \$protection = $protection et \$ancienneprotection = $ancienneprotection\n");

  // Génération pour les différentes combinaisons autorisation-matières
  foreach ( $combinaisons as $c )  {
    list($autorisation,$matieres) = explode('|',$c);
    $requete = ( $autorisation ) ? "FIND_IN_SET(matiere,'$matieres') AND (".requete_protection($autorisation).')' : 'protection = 0';
    $resultat = $mysqli->query("SELECT type, UNIX_TIMESTAMP(publi) AS publi, UNIX_TIMESTAMP(maj) AS maj, titre, lien, texte FROM recents
                                WHERE $requete AND publi < NOW() AND ( DATEDIFF(NOW(),publi) < 90 OR DATEDIFF(NOW(),maj) < 90 ) ORDER BY IF(maj>0,maj,publi) DESC");
    $rss = '';
    if ( $resultat->num_rows )  {
      while ( $r = $resultat->fetch_assoc() )  {
        $d = date(DATE_RSS,$r['maj'] ?: $r['publi']);
        // Modification pour les documents
        if ( $r['type'] == 3 )  {
          $r['titre'] .= strtok($r['texte'],'|');
          $r['texte'] = 'Document de '.strtok('|')." dans le répertoire <a href=\"${site}docs?rep=".strtok('|').'">'.strtok('|').'</a>';
        }
        if ( $r['maj'] )
          $r['titre'] .= ' (mise à jour)';
        $texte = preg_replace(array('/href="([^h])/','/\<\?/'),array("href=\"$site\\1",'<!?'),$r['texte']);
        $rss .= <<<FIN
    <item>
      <title><![CDATA[${r['titre']}]]></title>
      <link>$site${r['lien']}</link>
      <guid isPermaLink="false">${r['publi']}</guid>
      <description><![CDATA[$texte]]></description>
      <pubDate>$d</pubDate>
    </item>


FIN;
      }
      $resultat->free();
    }
    // Vérification des éventuels affichages différés
    $debut = '';
    $resultat = $mysqli->query("SELECT UNIX_TIMESTAMP(publi) AS publi FROM recents WHERE $requete AND publi > NOW() ORDER BY publi LIMIT 1");
    if ( $resultat->num_rows )  {
      $debut = '<?php if ( time() > '.($resultat->fetch_row()[0])." )  { define('OK',1); \$c='$c'; include('../../genere_rss.php'); exit(); } ?>\n";
      $resultat->free();
    }
    // Mise à jour du flux RSS
    $rep = 'rss/'.substr(sha1("?!${GLOBALS['mdp']}|$c"),0,20);
    if ( !is_dir($rep) )
      mkdir($rep,0777,true);
    $fichier = fopen($rep.'/rss.xml','wb');
    fwrite($fichier, $debut.str_replace('REP',$rep,$preambule).$rss."  </channel>\n</rss>\n");
    fclose($fichier);

    // Log
    fwrite($fichierlog, "  $c $rep\n");
  }
  fclose($fichierlog);
  return true;
}

// Fonction PHP pour le stockage dans la base MySQL de l'ordre "naturel" (1,2,10,11 et non 1,10,11,2)
// Remplace tout nombre par un nombre égal mais écrit sur 10 chiffres, complété par des zéros à gauche
// Utilisé pour les documents et les groupes
function zpad($s) {
  return preg_replace_callback('/(\d+)/', function($m){
    return(str_pad($m[1],10,'0',STR_PAD_LEFT)); }
  , $s);
}

////////////////////////////////////////////////////////////////////////////////
////////////////////////// Gestion des autorisations ///////////////////////////
////////////////////////////////////////////////////////////////////////////////

// Fonction d'accès en lecture ou en écriture
// Retourne 1 si accès en mode édition
// Retourne 2 si propriétaire de la page (professeur associé à la matière)
// Retourne 0 si accès en mode lecture uniquement
// Affiche une page de connexion si besoin (et arrête l'exécution)
// Affiche une page d'interdiction et arrête l'exécution sinon
// $protection est une valeur numérique.
// * si $protection = 0, accès autorisé sans connexion identifiée.
// * si 0 < $protection < 33, $protection est la représentation décimale de la
// valeur binaire PLCEI (types d'utilisateurs : professeurs, lycée, colleurs,
// élèves, invités) après y avoir retranché 1.
// Chaque 0 correspond aux accès autorisés, chaque 1 correspond aux protections
// (accès interdit pour ce type de compte).
// Exemple : 10 -> PLCEI=9=01001 -> autorisé pour P,C,E et interdit pour L et I.
// Le code 32 (interdit pour tous) correspond aux docs/reps/... non visibles.
// * si $protection = 32, page non affichée dans le menu, non visible sauf pour
// les professeurs associés à la matière
// $matiere est la matière associée à la page à afficher
// $titre est le titre de la page affichée si accès refusé ou connexion demandée
// $actuel est le lien du menu si accès refusé ou connexion demandée
// $edition est une valeur numérique
// * si $edition = 0, édition autorisée uniquement aux profs associés à la
// matière (cas par défaut) et aux administrateurs. Valeur 32 non utilisée.
// * si 0 < $edition < 32, $edition est la représentation décimale de la valeur
// binaire PLCEI après y avoir retranché 1. Chaque 1 correspond à une
// autorisation d'édition.
// Pour les profs, le 1 étend l'autorisation aux profs non associés à la matière.
// Pour les autres types, l'autorisation n'est valable que pour les utilisateurs
// associés à la matière.
// Seul un utilisateur ayant accès en lecture peut avoir accès en écriture.
function acces($protection,$matiere,$titre,$actuel,$mysqli,$edition=false)  {
  global $autorisation;
  // Si professeur ayant accès à une matière en tant que colleur, modification
  // globale de $autorisation et accès possible dans l'un ou l'autre des deux cas
  if ( ( $autorisation == 5 ) && strpos($_SESSION['matieres'],'c') && in_array("c$matiere",explode(',',$_SESSION['matieres'])) )  {
    $autorisation = 3;
    if ( !$protection || ( (32-$protection) & 20 ) )
      return intval( $edition && ($edition-1) & 20 );
    // On interdit la validation suivante, et on affichera la page d'erreur
    $protection = 32;
    $matiereassociee = false;
  }
  else
    $matiereassociee = $autorisation && in_array($matiere,explode(',',$_SESSION['matieres']));
  // Accès en lecture autorisé
  // On affiche si :
  //  * ressource non protégée ($protection = 0)
  //  * pour les profs, toute ressource où les profs sont autorisés ou toute 
  // ressource de leur matière (y compris les ressources désactivées)
  //  * pour les autres, seulement les ressources autorisées et correspondant
  // à une matière qui leur est associée
  $masque = 2**($autorisation-1);
  if ( !$protection || ( $autorisation == 5 ) && ( $matiereassociee || ( $protection < 17 ) ) || $matiereassociee && (32-$protection) & $masque )  {
    // Non connecté : pas d'édition
    if ( !$autorisation )
      return 0;
    // Professeur propriétaire
    if ( ( $autorisation == 5 ) && $matiereassociee )
      return 2;
    // Si $edition est nul, pas d'accès en écriture (autorisé uniquement au
    // professeur propriétaire)
    // Sinon, édition possible pour les profs hors matière si $edition>16, ou
    // pour les autres utilisateurs si la matière leur est associée
    return intval( $edition && ( ( $autorisation == 5 ) && ( $edition > 16 ) || $matiereassociee && ($edition-1) & $masque ) );
  }
  // Si page protégée et utilisateur non connecté : connexion demandée
  // login.php contient $mysqli->close() et fin()
  if ( !$autorisation )
    include('login.php');
  // Accès non autorisé
  debut($mysqli,$titre,'Vous n\'avez pas accès à cette page.',$autorisation,$actuel);
  $mysqli->close();
  fin();
}
// Définition de la chaîne de protection mysql pour récupérer les éléments,
// matière déjà vérifiée, sans mode édition. 
// Pour index.php, agenda.php, docs.php
function requete_protection($autorisation,$prefix='')  {
  // Cas des profs
  if ( $autorisation == 5 )
    return "( ${prefix}protection != 32 )";
  // Cas particulier des profs-colleurs
  if ( ( $autorisation == 3 ) && ( $_SESSION['autorisation'] == 5 ) && !$_SESSION['mode_lecture'] )
    return "( (32-${prefix}protection) & 20 )";
  return $autorisation ? "( ( ${prefix}protection = 0 ) OR (32-${prefix}protection) & ".(2**($autorisation-1)).' )' : "${prefix}protection = 0";
}
// Définition de la chaîne de protection mysql pour récupérer les éléments,
// matière déjà vérifiée, sans mode édition. 
// Pour index.php, agenda.php
function requete_edition($autorisation)  {
  // Cas particulier des profs-colleurs
  if ( ( $autorisation == 3 ) && ( $_SESSION['autorisation'] == 5 ) && !$_SESSION['mode_lecture'] )
    return '( edition AND (edition-1) & 20 )';
  return $autorisation ? '( edition AND (edition-1) & '.(2**($autorisation-1)).' )' : '0';
}

////////////////////////////////////////////////////////////////////////////////
//////////////////////////////// Affichage HTML ////////////////////////////////
////////////////////////////////////////////////////////////////////////////////

// Mise à jour du menu
// Utilise les données d'identifications présentes dans $_SESSION
// Stocke l'ensemble dans le champ menuelements de la table utilisateurs si
// $ecriture est vraie (sinon, il s'agit d'un menu fabriqué en mode lecture)
// N'est donc pas utilisé pour les utilisateurs non identifiés (autorisation=0)
// Invité/Élèves/Colleurs/Lycée : on voit ce qui est non vide et
// autorisé (matière associée et protection correcte)
// Prof : on voit tout ce qui n'est pas désactivé pour les matières associées
// et tout ce qui est non vide et autorisé pour les autres matières choisies
// Forme finale : liste des matieres|liste des pages|liste des réps|liste des
// icônes|liste des chaines pour chaque matière, qui sont chacune sous la forme
// mid:[p=id,id,id;][r=id,id,id;][i=pdcnt]
// !!! Fonction recopiée partiellement (sans mode lecture) dans la version multi
//     -> à modifier là-bas si on modifie ici !
function majmenu($mysqli,$autorisation,$ecriture=true)  {
  $matieres = $pages = $reps = array();
  // Récupération des matières à afficher dans le menu
  if ( $_SESSION['mode_lecture'] )
    $menumatieres = $_SESSION['matieres'];
  else  {
    $resultat = $mysqli->query("SELECT menumatieres FROM utilisateurs WHERE id=${_SESSION['id']}");
    $menumatieres = $resultat->fetch_row()[0];
    $resultat->free();
  }
  // Bout de requête pour les pages et répertoires ; pour les autres fonctions
  // spécifiques à chaque matière
  $masque = 2**($autorisation-1);
  if ( $autorisation == 5 )  {
    $requete_infosdocs = "protection < 17 AND FIND_IN_SET(matiere,'$menumatieres') OR FIND_IN_SET(matiere,'${_SESSION['matieres']}')";
    $requete_matieres = "
      SELECT 1 AS rang, ordre, id, progcolles < 2 AS p, docs < 2 AS d, cdt < 2 AS c, notescolles < 2 AS n, transferts < 2 AS t
      FROM matieres WHERE FIND_IN_SET(id,'${_SESSION['matieres']}')";
    // Cas des profs étant colleurs dans d'autres matières
    if ( strpos($_SESSION['matieres'],'c') )  {
      // $m ne contient que les matières où on est colleur
      $m = str_replace('c','',implode(',',array_filter(explode(',',$_SESSION['matieres']),function($v){return $v[0]=='c';})));
      $requete_infosdocs .= " OR ( protection = 0 OR ( (32-protection) & 20 ) ) AND FIND_IN_SET(matiere,'$m')";
      $requete_matieres = "( $requete_matieres ) UNION (
        SELECT 2 AS rang, ordre, id,
          progcolles = 1 AND ( progcolles_protection = 0 OR (32-progcolles_protection) & 20 ) AS p,
          docs = 1 AND ( docs_protection = 0 OR (32-docs_protection) & 20 ) AS d, 
          cdt = 1 AND ( cdt_protection = 0 OR (32-cdt_protection) & 20 ) AS c,
          notescolles < 2 AS n, (32-transferts_protection) & 4 as t
        FROM matieres WHERE FIND_IN_SET(id,'$m') )";
    }
  }
  else  {
    $notescolles = ( $autorisation == 2 ) ? 'notescolles = 1' : ( ( $autorisation == 3 ) ? 'notescolles < 2' : '0' );  
    $requete_infosdocs = "( protection = 0 OR (32-protection) & $masque ) AND FIND_IN_SET(matiere,'$menumatieres')";
    $requete_matieres = "
      SELECT 1 AS rang, ordre, id,
        progcolles = 1 AND ( progcolles_protection = 0 OR (32-progcolles_protection) & $masque ) AS p,
        docs = 1 AND ( docs_protection = 0 OR (32-docs_protection) & $masque ) AS d,
        cdt = 1 AND ( cdt_protection = 0 OR (32-cdt_protection) & $masque ) AS c,
        $notescolles AS n, transferts = 1 AND (32-transferts_protection) & $masque AS t
      FROM matieres WHERE FIND_IN_SET(id,'${_SESSION['matieres']}')";
  }
  // Contenu pour les matières non associées ajoutées au menu
  if ( ( $_SESSION['matieres'] != $menumatieres ) && ( $autresmatieres = implode(',',array_diff(explode(',',$menumatieres),explode(',',str_replace('c','',$_SESSION['matieres'])))) ) )  {
    $protection = ( $autorisation == 5 ) ? '< 17' : '= 0';
    $requete_matieres = "( $requete_matieres ) UNION ( 
      SELECT 3 AS rang, ordre, id,
        progcolles = 1 AND progcolles_protection $protection AS p,
        docs = 1 AND docs_protection $protection AS d,
        cdt = 1 AND cdt_protection $protection AS c, 0 AS n,
        transferts = 1 AND transferts_protection $protection AS t
      FROM matieres WHERE FIND_IN_SET(id,'$autresmatieres') )";
  }
  
  // Ressources sans matière associée
  // Icônes nécessaires, parmi agenda, progcolles, docs, notescolles, transferts, mails
  $icones = array('d'=>0, 'p'=>0, 'a'=>0, 'm'=>0, 't'=>0, 'n'=>0);
  // Valeurs sans matières : docs, agenda, mails, transferts (seront complétées après)
  $resultat = $mysqli->query("SELECT id FROM reps WHERE $requete_infosdocs LIMIT 1");
  if ( $icones['d'] = $resultat->num_rows )
    $resultat->free();
  $resultat = $mysqli->query("SELECT val = 0 OR (32-val) & $masque FROM prefs WHERE nom = 'agenda_protection'");
  $icones['a'] = $resultat->fetch_row()[0];
  $resultat->free();
  $resultat = $mysqli->query("SELECT val >> 4*($autorisation-2) & 15 > 0 FROM prefs WHERE nom = 'autorisation_mails'");
  $icones['m'] = $resultat->fetch_row()[0];
  $resultat->free();
  // Bug corrigé en 2025 : "profs hors matière" non autorisés, ce qui n'a aucune conséquence  
  //$resultat = $mysqli->query("SELECT (32-val) & $masque > 0 FROM prefs WHERE nom = 'transferts_general_protection'");
  $resultat = $mysqli->query("SELECT (32-val) | 16 & $masque > 0 FROM prefs WHERE nom = 'transferts_general_protection'");
  $icones['t'] = $resultat->fetch_row()[0];
  $resultat->free();
  // Pages d'information, toutes matières
  $resultat = $mysqli->query("SELECT matiere, GROUP_CONCAT(id ORDER BY ordre) AS ids FROM pages WHERE id > 1 AND ( $requete_infosdocs ) GROUP BY matiere");
  if ( $resultat->num_rows )  {
    while ( $r = $resultat->fetch_row() )
      $pages[$r[0]] = $r[1];
    $resultat->free();
  }
  // Répertoires apparaissant dans le menu, toutes matières
  $resultat = $mysqli->query("SELECT matiere, GROUP_CONCAT(id ORDER BY nom) AS ids FROM reps WHERE menu = 1 AND ( $requete_infosdocs ) GROUP BY matiere");
  if ( $resultat->num_rows )  {
    while ( $r = $resultat->fetch_row() )
      $reps[$r[0]] = $r[1];
    $resultat->free();
  }
  // Pages et répertoires non associés à une matière
  $menuelements = ( isset($pages[0]) ) ? "p=${pages[0]};" : '';
  $menuelements .= ( isset($reps[0]) ) ? "r=${reps[0]};" : '';
  $menuelements .= ( $icones['t'] ) ? "i=t;" : '';
  $menuelements = ( $menuelements ) ? '|0:'.substr($menuelements,0,-1) : '';
  
  // Génération
  // Fabrique une chaine de la forme "|mid:p=id,id,id;r=id,id,id;i=pdctn" (pages, répertoires, icônes)
  $resultat = $mysqli->query("$requete_matieres ORDER BY rang, ordre");
  if ( $resultat->num_rows )  {
    while ( $r = $resultat->fetch_assoc() )  {
      $matieres[] = $r['id'];
      $matiere = 'i=';
      if ( $r['p'] )  { $icones['p'] = 1; $matiere .= 'p'; }
      if ( $r['d'] )  { $icones['d'] = 1; $matiere .= 'd'; if ( isset($reps[$r['id']]) )  $matiere = "r=${reps[$r['id']]};${matiere}"; }
      if ( $r['c'] )  { $icones['c'] = 1; $matiere .= 'c'; }
      if ( $r['t'] )  { $icones['t'] = 1; $matiere .= 't'; }
      if ( $r['n'] )  { $icones['n'] = 1; $matiere .= 'n'; }
      if ( isset($pages[$r['id']]) )
        $matiere = "p=${pages[$r['id']]};$matiere";
      if ( strlen($matiere) > 2 ) 
        $menuelements .= "|${r['id']}:$matiere";
    }
    $resultat->free();
  }
    
  // Mode lecture : désactivation des icônes de courriels, de transferts et des notes
  //if ( $_SESSION['mode_lecture'] )
  //  $icones['m'] = $icones['t'] = $icones['n'] = 0;
  // Ajout des matières, pages et répertoires à précharger
  $menuelements = implode(',',$matieres).'|'.implode(',',$pages).'|'.implode(',',$reps).'|'.implode(array_keys($icones,1)).( $menuelements ?: '|' );
  // Écriture
  if ( $ecriture )  {
    $mysqli2 = connectsql(true);
    $mysqli2->query("UPDATE utilisateurs SET menuelements = '$menuelements' WHERE id = ${_SESSION['id']}");
    $mysqli2->close();
  }
  return $menuelements;
}

// En-têtes HTML et début de page
// $donnees est false si pas en mode édition, une array sinon, contenant
//  * protection et edition -> pour des icônes dans le titre (facultatif)
//    à ne pas mettre pour les pages de gestion
//  * action -> pour l'envoi ajax (obligatoire) 
//  * matiere -> pour l'envoi ajax (facultatif)
//  * css -> pour l'ajout d'un fichier css (datetimepicker ou colpick, facultatif)
function debut($mysqli,$titre,$message,$autorisation,$actuel=false,$donnees=false)  {
  // Menu seulement si $actuel non vide
  if ( $actuel )  {

    // Icônes principales (accueil, agenda, impression, rss)
    $recents = $_SESSION['recents'] ?? '';
    $icones = <<<FIN
    <a class="icon-menu" title="Afficher le menu"></a>
    <a class="icon-accueil" href="." title="Revenir à la page d'accueil"></a>
    <a class="icon-recent" href="recent" title="Voir les $recents nouveaux contenus">$recents</a>

FIN;
    
    // Traitement du mode lecture : se faire passer temporairement pour un autre type de compte
    if ( $autorisation && $_SESSION['mode_lecture'] )
      $autorisation = $_SESSION['mode_lecture']-1;
    
    // Utilisation des éléments enregistrés pour les utilisateurs connectés
    if ( $autorisation )  {
      // Récupération et éventuellement génération
      if ( $_SESSION['mode_lecture'] ) 
        $menuelements = majmenu($mysqli,$autorisation,false);
      else  {
        $resultat = $mysqli->query("SELECT menuelements FROM utilisateurs WHERE id = ${_SESSION['id']}");
        // Si le compte vient d'être supprimé
        if ( !$resultat->num_rows )  {
          suppression_session(1);
          exit();
        }
        $menuelements = ( $resultat->fetch_row()[0] ) ?: majmenu($mysqli,$autorisation);
        $resultat->free();
      }
      // Récupération des clés et des noms pour les matières, pages, répertoires
      list($m, $p, $r, $i, $blocs) = explode('|',$menuelements,5);
      $matieres = $pages = $reps = array();
      if ( $m )  {
        $resultat = $mysqli->query("SELECT id,cle,nom FROM matieres WHERE FIND_IN_SET(id,'$m')");
        while ( $r1 = $resultat->fetch_assoc() )
          $matieres[$r1['id']] = $r1;
        $resultat->free();
      }
      if ( $p )  {
        $resultat = $mysqli->query("SELECT id,cle,nom FROM pages WHERE FIND_IN_SET(id,'$p')");
        while ( $r1 = $resultat->fetch_assoc() )
          $pages[$r1['id']] = $r1;
        $resultat->free();
      }
      if ( $r )  {
        $resultat = $mysqli->query("SELECT id,nom FROM reps WHERE FIND_IN_SET(id,'$r')");
        while ( $r1 = $resultat->fetch_assoc() )
          $reps[$r1['id']] = $r1;
        $resultat->free();
      }
      // Traitement des icônes
      if ( strpos($i,'d') !== false )  $icones .= "    <a class=\"icon-rep\" href=\"docs\" title=\"Voir les documents à télécharger\"></a>\n";
      if ( strpos($i,'p') !== false )  $icones .= "    <a class=\"icon-progcolles\" href=\"progcolles\" title=\"Voir les programmes de colles\"></a>\n";
      if ( strpos($i,'a') !== false )  $icones .= "    <a class=\"icon-agenda\" href=\"agenda\" title=\"Voir l'agenda\"></a>\n";
      if ( strpos($i,'m') !== false )  $icones .= "    <a class=\"icon-mail\" href=\"mail\" title=\"Envoyer un mail\"></a>\n";
      if ( strpos($i,'t') !== false )  $icones .= "    <a class=\"icon-transfert\" href=\"transferts\" title=\"Transférer des documents\"></a>\n";
      if ( strpos($i,'n') !== false )  $icones .= "    <a class=\"icon-notescolles\" href=\"notescolles\" title=\"Consulter les notes de colles\"></a>\n";
      if ( ( $autorisation > 1 ) && $_SESSION['compteglobal'] )
        $icones .= "    <a class=\"icon-echange\" title=\"Changer de Cahier\"></a>\n";
      $icones .= '    <a class="icon-deconnexion" title="Se déconnecter"></a>';
      // Traitement du reste du menu
      $menu = '';
      if ( $blocs )  {
        $blocs = explode('|',$blocs);
        foreach ( $blocs as $bloc )  {
          $mid = strstr($bloc,':',true);
          // Titre de matière
          if ( $mid )  {
            $menu .= "    <h3>{$matieres[$mid]['nom']}</h3>\n";
            $cle = $matieres[$mid]['cle'];
          }
          else
            $menu = "    <hr>\n";
          $bloc = substr($bloc,strpos($bloc,':')+1).';';
          // Pages
          if ( $bloc[0] == 'p' )  {
            $clepage = ( $mid ) ? "$cle/" : '';
            foreach( explode(',',substr($bloc,2,strpos($bloc,';')-2)) as $pid )
              $menu .= "    <a href=\".?$clepage{$pages[$pid]['cle']}\">{$pages[$pid]['nom']}</a>\n";
            $bloc = substr($bloc,strpos($bloc,';')+1);
          }
          // Répertoires
          $menurep = '';
          if ( $bloc && ( $bloc[0] == 'r' ) )  {
            foreach( explode(',',substr($bloc,2,strpos($bloc,';')-2)) as $rid )
              // Protection par isset : pour éviter une erreur si répertoire supprimé
              $menurep .= ( ( $rid > 1 ) && isset($reps[$rid]) ) ? "    <a class=\"menurep\" href=\"docs?rep={$reps[$rid]['id']}\">{$reps[$rid]['nom']}</a>\n" : ' ';
            $bloc = substr($bloc,strpos($bloc,';')+1);
            // Si documents généraux : on affiche ça en début de menu
            if ( !$mid && $menurep )
              $menu .= "    <a href=\"docs?general\"><span class=\"icon-rep\"></span>&nbsp;Documents généraux</a>\n$menurep";
          }
          // Éléments avec icônes
          if ( $bloc && ( $bloc[0] == 'i' ) )  {
            if ( strpos($bloc,'p',1) )  $menu .= "    <a href=\"progcolles?$cle\"><span class=\"icon-progcolles\"></span>&nbsp;Programme de colles</a>\n";
            if ( strpos($bloc,'d',1) )  $menu .= "    <a href=\"docs?$cle\"><span class=\"icon-rep\"></span>&nbsp;Documents à télécharger</a>\n$menurep";
            if ( strpos($bloc,'c',1) )  {
              $menu .= "    <a href=\"cdt?$cle\"><span class=\"icon-cdt\"></span>&nbsp;Cahier de texte</a>\n";
              if ( ( substr($actuel,0,strlen($cle)+4) == "cdt?$cle" ) && ( $autorisation == 5 ) && in_array($mid,explode(',',$_SESSION['matieres'])) )
                $menu .= "    <a class=\"menurep\" href=\"cdt?$cle&seances\">Types de séances</a>\n    <a class=\"menurep\" href=\"cdt?$cle&raccourcis\">Raccourcis de séances</a>\n";            
            }
            if ( strpos($bloc,'t',1) )  
              $menu .= ( $mid ) ? "    <a href=\"transferts?$cle\"><span class=\"icon-transfert\"></span>&nbsp;Transfert de documents</a>\n" 
                                : "    <a href=\"transferts?general\"><span class=\"icon-transfert\"></span>&nbsp;Transfert de docs généraux</a>\n";
            if ( strpos($bloc,'n',1) )  {
              $menu .= "    <a href=\"notescolles?$cle\"><span class=\"icon-notescolles\"></span>&nbsp;Notes de colles</a>\n";
              if ( ( substr($actuel,0,strlen($cle)+12) == "notescolles?$cle" ) && ( $autorisation == 5 ) && in_array($mid,explode(',',$_SESSION['matieres'])) )
                $menu .= "    <a class=\"menurep\" href=\"notescolles?$cle&gestion\">Colles de mes colleurs</a>\n    <a class=\"menurep\" href=\"notescolles?$cle&tableau\">Tableau de notes</a>\n";            
            }
          }
        }
      }
      // Autres éléments : préférences, blog, relève des colles
      if ( !$_SESSION['mode_lecture'] )  {
        $autresactions = '    <hr>';
        if ( $autorisation > 1 )
          $autresactions .= "\n    <a href=\"prefs\"><span class=\"icon-prefs\"></span>&nbsp;Mes préférences</a>";
        if ( $GLOBALS['interfaceglobale'] )
          $autresactions .= "\n    <a href=\"blogcdp\"><span class=\"icon-messages\"></span>&nbsp;Le blog de CdP</a>";
        // Relève des colles, compte lycée ou administrateur
        if ( ( $autorisation == 4 ) || $_SESSION['admin'] )
          $autresactions .= "\n    <a href=\"relevecolles\"><span class=\"icon-notescolles\"></span>&nbsp;Relève des colles</a>";
        if ( strlen($autresactions) > 8 )
          $menu .= "$autresactions\n";
        // Liens d'administration, administrateurs seulement
        if ( $_SESSION['admin'] )
          $menu .= <<<FIN
    <h3>Gestion du site</h3>
    <a href="reglages">Les réglages</a>
    <a href="matieres">Les matières</a>
    <a href="planning">Le planning annuel</a>
    <a href="pages">Les pages</a>
    <h3>Gestion des utilisateurs</h3>
    <a href="utilisateurs">Les comptes</a>
    <a href="utilisateurs-mails">Les adresses électroniques</a>
    <a href="utilisateurs-matieres">Les associations de matières</a>
    <a href="groupes">Les groupes</a>

FIN;
        // Liens de visualisation d'administration, comptes profs
        elseif ( $autorisation == 5 )
          $menu .= <<<FIN
    <h3>Gestion du site</h3>
    <a href="matieres">Les matières</a>
    <a href="planning">Le planning annuel</a>
    <a href="pages">Les pages</a>
    <h3>Gestion des utilisateurs</h3>
    <a href="utilisateurs-mails">Les adresses électroniques</a>
    <a href="utilisateurs-matieres">Les associations de matières</a>
    <a href="groupes">Les groupes</a>

FIN;
        // Liens de visualisation d'administration, comptes lycée
        elseif ( $autorisation == 4 )
          $menu .= <<<FIN
    <h3>Gestion du site</h3>
    <a href="matieres">Les matières</a>
    <a href="planning">Le planning annuel</a>
    <h3>Gestion des utilisateurs</h3>
    <a href="utilisateurs-mails">Les adresses électroniques</a>
    <a href="utilisateurs-matieres">Les associations de matières</a>
    <a href="groupes">Les groupes</a>

FIN;
      }
    }
    // Cas des utilisateurs non connectés
    else  {
      // Icônes
      $resultat = $mysqli->query("SELECT val FROM prefs WHERE nom = 'icones_auto0'");
      $val = $resultat->fetch_row()[0];
      $resultat->free();
      if ( $val & 1 )  $icones .= "    <a class=\"icon-rep\" href=\"docs\" title=\"Voir les documents à télécharger\"></a>\n";
      if ( $val & 2 )  $icones .= "    <a class=\"icon-progcolles\" href=\"progcolles\" title=\"Voir les programmes de colles\"></a>\n";
      if ( $val & 4 )  $icones .= "    <a class=\"icon-agenda\" href=\"agenda\" title=\"Voir l'agenda\"></a>\n";
      $icones .= '    <a class="icon-connexion" title="Se connecter"></a>';
      // Menu : pages d'information générales
      $resultat = $mysqli->query("SELECT cle, nom FROM pages WHERE matiere = 0 AND id > 1 AND protection < 32 ORDER BY ordre");
      $menu = '';
      if ( $resultat->num_rows )  {
        $menu = "    <hr>\n";
        while ( $r = $resultat->fetch_assoc() )
          $menu .= "    <a href=\".?${r['cle']}\">${r['nom']}</a>\n";
      }
      $resultat->free();
      // Menu : répertoires généraux
      $resultat = $mysqli->query("SELECT id, nom FROM reps WHERE matiere = 0 AND menu = 1 AND protection < 32 ORDER BY nom");
      if ( $resultat->num_rows )  {
        if ( !$menu ) 
          $menu = "    <hr>\n";
        $menu .= "    <a href=\"docs\"><span class=\"icon-rep\"></span>&nbsp;Documents généraux</a>\n";
        while ( ( $r = $resultat->fetch_assoc() ) && ( $r['id'] > 1 ) ) 
          $menu .= "    <a class=\"menurep\" href=\"docs?rep=${r['id']}\">${r['nom']}</a>\n";
        $resultat->free();
      }
      // Récupération et affichage des matières
      $resultat = $mysqli->query('SELECT * FROM (
                                    SELECT m.id, m.cle, m.nom, progcolles = 1 AS progcolles, docs = 1 AS docs, cdt = 1 AS cdt,
                                    GROUP_CONCAT(CONCAT(m.cle,"/",p.cle) ORDER BY p.ordre SEPARATOR "//") AS pcle, GROUP_CONCAT(p.nom ORDER BY p.ordre SEPARATOR "//") AS pnom
                                    FROM matieres AS m LEFT JOIN ( SELECT * FROM pages WHERE protection < 32 ) AS p ON p.matiere = m.id
                                    GROUP BY m.id ORDER BY m.ordre
                                  ) AS t WHERE progcolles = 1 OR docs = 1 OR cdt = 1 OR pnom IS NOT NULL');
      if ( $resultat->num_rows )  {
        while ( $r = $resultat->fetch_assoc() )  {
          $menu .= "    <h3>${r['nom']}</h3>\n";
          if ( !is_null($r['pcle']) )  {
            $pcle = explode('//',$r['pcle']);
            $pnom = explode('//',$r['pnom']);
            $nom = $pnom[0];
            foreach ( $pcle as $cle )  {
              $menu .= "    <a href=\".?$cle\">$nom</a>\n";
              $nom = next($pnom);
            }
          }
          if ( $r['progcolles'] )
            $menu .= "    <a href=\"progcolles?${r['cle']}\"><span class=\"icon-progcolles\"></span>&nbsp;Programme de colles</a>\n";
          if ( $r['docs'] )  {
            $menu .= "    <a href=\"docs?${r['cle']}\"><span class=\"icon-rep\"></span>&nbsp;Documents à télécharger</a>\n";
            $resultat_doc = $mysqli->query("SELECT id, nom FROM reps WHERE matiere = ${r['id']} AND menu = 1 AND protection < 32 ORDER BY nom");
            if ( $resultat_doc->num_rows )  {
              while ( $d = $resultat_doc->fetch_assoc() )
                $menu .= "    <a class=\"menurep\" href=\"docs?rep=${d['id']}\">${d['nom']}</a>\n";        
              $resultat_doc->free();
            }
          }
          if ( $r['cdt'] )
            $menu .= "    <a href=\"cdt?${r['cle']}\"><span class=\"icon-cdt\"></span>&nbsp;Cahier de texte</a>\n";
        }
        $resultat->free();
      }
    }

    // Menu final
    if ( $actuel2 = strstr($actuel,'?',true) )
      $icones = str_replace("href=\"$actuel2\"","id=\"actuel2\" href=\"$actuel2\"",$icones);
    $menu = <<<FIN
<nav>
  <div id="iconesmenu">
$icones
  </div>
  <div id="menu">
$menu  </div>
</nav>
FIN;
    $menu = str_replace("href=\"$actuel\"","id=\"actuel\" href=\"$actuel\"",$menu);
  }
  else
    $menu = '';
  
  //////////
  // HTML //
  //////////
  
  // Message si non vide
  if ( $message )
    $message = "  <div class=\"warning\">$message</div>\n";
  elseif ( $autorisation && $_SESSION['admin'] && ( basename($_SERVER['PHP_SELF']) == 'index.php' ) )  {
    $resultat = $mysqli->query('SELECT id FROM utilisateurs WHERE mdp LIKE "*%"');
    if ( $n = $resultat->num_rows )  {
      $resultat->free();
      $n = ( $n > 1 ) ? "$n comptes" : "1 compte";
      $message = '  <div class="warning">Il y a actuellement '.(( $n > 1 ) ? "$n comptes" : "1 compte").' en attente de validation de votre part. C\'est assez urgent... Il faut aller sur la page de gestion des <a href="utilisateurs">utilisateurs</a> pour les valider.</div>';
    }
  }
  // Flux RSS
  $rss = substr(sha1( ( $autorisation ) ? "?!${GLOBALS['mdp']}|$autorisation|${_SESSION['matieres']}" : "?!${GLOBALS['mdp']}|0|toutes" ),0,20);
  // Token CSRF
  $token = ( isset($_SESSION['csrf-token']) ) ? " data-csrf-token=\"${_SESSION['csrf-token']}\"" : '';
  // Ajouts pour édition
  if ( $donnees )  {
    $bodydata = " data-action=\"${donnees['action']}\"";
    $icones = '';
    if ( isset($donnees['matiere']) )
      $bodydata .= " data-matiere=\"${donnees['matiere']}\"";
    // $donnees ne contient pas toujours protection et edition
    // Le mode de lecture n'a de sens que sur les pages protégées 
    if ( count($donnees) > 3 )  {
      $bodydata .= " data-protection=\"${donnees['protection']}\" data-edition=\"${donnees['edition']}\"";
      if ( $_SESSION['mode_lecture'] )  {
        $bodydata .= " data-modelecture=\"${_SESSION['mode_lecture']}\"" ;
        $icones = '';
      }
      else  {
        $icones = ( $donnees['protection'] ) ? '<span id="affprotection" class="icon-lock'.( ( $donnees['protection'] == 32 ) ? 'total' : '' ).' affichable"></span>' : '';
        $icones .= ( $donnees['edition'] ) ? '<span id="affedition" class="icon-edite affichable"></span>' : '';
      }
    }
  }
  else
    $bodydata = $icones = '';

  // Fichiers CSS supplémentaires
  $css = ( isset($donnees['css']) ) ? "\n  <link rel=\"stylesheet\" href=\"css/${donnees['css']}.min.css\">" : '';
  // Affichage
  echo <<<FIN
<!doctype html>
<html lang="fr">
<head>
  <title>$titre</title>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, height=device-height, initial-scale=1.0">
  <link rel="stylesheet" href="css/style.min.css?v=1200">
  <link rel="stylesheet" href="css/icones.min.css?v=1200">$css
  <script type="text/javascript" src="js/jquery.min.js"></script>
  <script type="text/javascript" src="js/commun.min.js?v=1200"></script>
  <link rel="alternate" type="application/rss+xml" title="Flux RSS" href="rss/$rss/rss.xml">
</head>
<body$token$bodydata>

<header><h1>$titre$icones</h1></header>

$menu

<section>
$message
FIN;
}

// Bas de page
function fin($edition = false, $mathjax = false, $script = '', $script2 = '')  {
  // MathJax chargé seulement si besoin
  $mathjax = ( $mathjax ) ? '
<script type="text/javascript" src="/MathJax/MathJax.js?config=TeX-AMS-MML_HTMLorMML"></script>
<script type="text/x-mathjax-config">MathJax.Hub.Config({tex2jax:{inlineMath:[["$","$"],["\\\\(","\\\\)"]]}});</script>' : '';
  // Édition possible si $edition est true
  $js = ( $edition ) ? '<script type="text/javascript" src="js/edition.min.js?v=1201"></script>' : '<script type="text/javascript" src="js/lecture.min.js?v=1201"></script>';
  if ( $script )  {
    $js .= "\n<script type=\"text/javascript\" src=\"js/$script.min.js?v=1200\"></script>";
    if ( $script2 )
      $js .= "\n<script type=\"text/javascript\" src=\"js/$script2.min.js?v=1200\"></script>";
  }
  // Affichage de message si $_SESSION['message']
  if ( isset($_SESSION['message']) )  {
    $m = json_decode($_SESSION['message'],true);
    $m = "\n<script type=\"text/javascript\">$( function() { affiche(\"${m['message']}\",'${m['etat']}'); });</script>";
    unset($_SESSION['message']);
  }
  else
    $m = '';
  echo <<<FIN

</section>

<footer>Ce site est réalisé par le logiciel <a href="http://cahier-de-prepa.fr">Cahier de prépa</a>, publié sous <a href="Licence_CeCILL_V2-fr.html">licence libre</a>.&nbsp;<span class="icon-theme" title="Changer le thème lumineux/sombre"></span>&nbsp;<a class="icon-python" href="/basthon" title="Coder en Python directement dans votre navigateur"></a></footer>

<div id="load"><img src="js/ajax-loader.gif"></div>
$js$mathjax$m

</body>
</html>
FIN;
  exit();
}

// Affichage des semaines
function format_date($date)  {
  $semaine = array('dimanche','lundi','mardi','mercredi','jeudi','vendredi','samedi');
  $mois = array('','janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre');
  return $semaine[substr($date,0,1)].' '.substr($date,7).( ( substr($date,7) == '1' ) ? 'er' : '' ).' '.$mois[intval(substr($date,5,2))].' '.substr($date,1,4);
}

////////////////////////////////////////////////////////////////////////////////
///////////////////////// Transformations spécifiques //////////////////////////
////////////////////////////////////////////////////////////////////////////////

// Validation des dates-heures reçues pour modification
// Fonction protégée contre le nom-remplissage
// Retourne 0 si date non valide, ou la date au format que l'on peut comparer ou
// stocker dans les bases de données.
// $champ : chaîne indiquant l'indice dans $_REQUEST à utiliser
// $heure : si on doit prendre en compte l'heure, true par défaut
function valide_date($champ, $heure = true)  {
  if ( $heure )
    return ( $d = strtotime(str_replace(['/','h'],['-',':'],$_REQUEST[$champ] ?? '')) ) ? date('Y-m-d H:i',$d) : 0 ;
  return ( $d = strtotime(str_replace('/','-',$_REQUEST[$champ] ?? '')) ) ? date('Y-m-d',$d) : 0 ;
}

// Récupération de l'icone ou du type MIME à partir d'une extension
// $ext est l'extension à transformer
// $action = 0 : obtenir la classe CSS de l'icône (par défaut) ; 1 : type MIME 
function transforme_extension($ext,$action=0)  {
  $ext = strtolower($ext); // Sécurité normalement inutile
  if ( $action )  {
    switch ( $ext )  {
      case 'pdf':  $type = 'application/pdf'; break;
      case 'doc':  $type = 'application/msword'; break;
      case 'odt':  $type = 'application/vnd.oasis.opendocument.text'; break;
      case 'docx': $type = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'; break;
      case 'xls':  $type = 'application/vnd.ms-excel'; break;
      case 'ods':  $type = 'application/vnd.oasis.opendocument.spreadsheet'; break;
      case 'xlsx': $type = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'; break;
      case 'ppt':  $type = 'application/vnd.ms-powerpoint'; break;
      case 'odp':  $type = 'application/vnd.oasis.opendocument.presentation'; break;
      case 'pptx': $type = 'application/vnd.openxmlformats-officedocument.presentationml.presentation'; break;
      case 'jpg':
      case 'jpeg': $type = 'image/jpeg'; break;
      case 'png':  $type = 'image/png'; break;
      case 'gif':  $type = 'image/gif'; break;
      case 'svg':  $type = 'image/svg+xml'; break;
      case 'tif':
      case 'tiff': $type = 'image/tiff'; break;
      case 'bmp':  $type = 'image/x-ms-bmp'; break;
      case 'ps':
      case 'eps':  $type = 'application/postscript'; break;
      case 'avi':  $type = 'video/x-msvideo'; break;
      case 'mpeg':
      case 'mpg':  $type = 'video/mpeg'; break;
      case 'wmv':  $type = 'video/x-ms-wmv'; break;
      case 'mp4':  $type = 'video/mp4'; break;
      case 'ogg':
      case 'ogv':  $type = 'video/ogg'; break;
      case 'webm': $type = 'video/webm'; break;
      case 'qt':
      case 'mov':  $type = 'video/quicktime'; break;
      case 'mkv':  $type = 'video/x-matroska'; break;
      case '3gp':  $type = 'video/3gpp'; break;
      case 'mp3':  $type = 'audio/mpeg'; break;
      case 'oga':  $type = 'audio/ogg'; break;
      case 'wma':  $type = 'audio/x-ms-wma'; break;
      case 'wav':  $type = 'audio/x-wav'; break;
      case 'ra':
      case 'rm':   $type = 'audio/x-pn-realaudio'; break;
      case 'txt':  $type = 'text/plain'; break;
      case 'rtf':  $type = 'application/rtf'; break;
      case 'zip':  $type = 'application/zip'; break;
      case 'rar':  $type = 'application/rar'; break;
      case '7z':   $type = 'application/x-7z-compressed'; break;
      case 'sh':   $type = 'text/x-sh'; break;
      case 'py':   $type = 'text/x-python'; break;
      case 'swf':  $type = 'application/x-shockwave-flash'; break;
      default :    $type = 'application/octet-stream';
    }
    return $type;
  }
  switch ( $ext )  {
    case 'pdf': case 'dvi': $icone = 'pdf'; break;
    case 'py' : $icone = 'py'; break;
    case 'sql': $icone = 'sql'; break;
    case 'db': case 'db3': case 'sqlite': case 'sq3': $icone = 'db'; break;
    case 'doc': case 'odt': case 'docx': $icone = 'doc'; break;
    case 'xls': case 'ods': case 'xlsx': case 'csv': $icone = 'xls'; break;
    case 'ppt': case 'odp': case 'pptx': case 'pps': $icone = 'ppt'; break;
    case 'jpg': case 'jpeg': case 'jpe': case 'png': case 'gif': case 'svg': case 'tif': case 'tiff': case 'bmp': case 'ps': case 'eps': $icone = 'jpg'; break;
    case 'mp3': case 'ogg': case 'oga': case 'wma': case 'wav': case 'ra': case 'rm': $icone = 'mp3'; break;
    case 'mp4': case 'avi': case 'mpeg': case 'mpg': case 'wmv': case 'ogv': case 'qt': case 'mov': case 'mkv': case 'flv': case 'swf': $icone = 'mp4'; break;
    case 'zip': case 'rar': case '7z': case 'jar': case 'dmg': $icone = 'zip'; break;
    case 'apk': $icone = 'apk'; break;
    case 'exe': case 'sh': case 'ml': case 'mw': case 'msi': $icone = 'exe'; break;
    case 'tex': $icone = 'tex'; break;
    case 'ggb': case 'htm': case 'mht': case 'rw3': case 'sce': case 'slx': case 'vpp': $icone = 'cod'; break;
    default: $icone = '';
  }
  return $icone ? 'icon-doc-'.$icone : 'icon-doc';
}

// Encodage conditionnel pour l'envoi de mails
function encode_mailheaders($chaine)  {
  return preg_match('/[^\x20-\x7f]/', $chaine) ? '=?UTF-8?B?'.base64_encode($chaine).'?=' : $chaine;
}
?>
