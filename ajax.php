<?php
// Sécurité
define('OK',1);
// Configuration
include('config.php');
// Fonctions
include('fonctions.php');

// Vérification du token CSRF
if ( !isset($_REQUEST['csrf-token']) || isset($_SESSION['csrf-token']) && ( $_REQUEST['csrf-token'] != $_SESSION['csrf-token'] ) )
  exit('{"etat":"nok","message":"Accès non autorisé"}');

// Test de connexion light pour reconnexion complète avant envoi d'un document
// (docs.php, transferts.php, mail.php)
if ( $autorisation && isset($_REQUEST['verifconnexion']) && connexionlight() )  {
  // Si connexion complète, modification du timeout pour autoriser un 
  // envoi sur une durée de 1h
  $_SESSION['time'] = max($_SESSION['time'],time()+3600);
  // Message inutile, non affiché
  exit('{"etat":"ok","message":""}'); 
}

// Récupération de l'action
if ( !$action = ( $_REQUEST['action'] ?? '' ) )
  exit('{"etat":"nok","message":"Aucune action effectuée"}');

// Demande de déconnexion
if ( $action == 'deconnexion' )  {
  suppression_session();
  // Recharge immédiate, donc besoin de $_SESSION['message']
  exit($_SESSION['message'] = '{"etat":"ok","message":"Déconnexion réussie","reload":"2"}');
}
// Si non autorisé, la session a dû expirer : il faut se reconnecter
if ( $autorisation == 0 )
  exit('{"etat":"login"}');

// Cas du changement du mode de lecture
// Le mode lecture est activable par n'importe quel utilisateur, mais utilisable 
// uniquement sur les pages où l'utilisateur est éditeur ou s'il est administrateur
// grâce à l'affectation $mode_lecture = ( $edition || $admin ) ? $_SESSION['mode_lecture'] : 0;
// C'est donc inoffensif de ne pas protéger le passage en mode_lecture.
// Valeurs : 0 pour annuler, 1 à 6 pour voir selon l'autorisation mode_lecture - 1
if ( ( $action == 'ml' ) && in_array($m = intval($_REQUEST['mode'] ?? ''), array(0,1,2,3,4,5,6)) )  {
  $_SESSION['mode_lecture'] = $m;
  if ( $m )  {
    $compte = array('utilisateur non connecté','compte invité','élève','colleur','compte de type lycée','professeur non associé à la matière')[$m-1];
    exit($_SESSION['message'] = "{\"etat\":\"ok\",\"message\":\"Vous voyez désormais les contenus comme un $compte.\",\"reload\":\"2\"}");
  }
  else
    exit($_SESSION['message'] = '{"etat":"ok","message":"Vous voyez à nouveau les contenus normalement.","reload":"2"}');
}

// Si mode lecture activé : interdiction de faire quoi que ce soit
if ( $_SESSION['mode_lecture'] )  {
  $compte = array('utilisateur non connecté','compte invité','élève','colleur','compte de type lycée','professeur non associé à la matière')[$_SESSION['mode_lecture']-1];
  exit("{\"etat\":\"nok\",\"message\":\"Vous voyez actuellement les contenus comme un $compte. Vous ne pouvez pas faire de modification dans ce mode.\"}");
}

// Répartition des actions
switch( $action )  {
  // Actions réservées aux éditeurs 
  case 'infos': case 'supprime-infos': case 'ajout-info': case 'reps': case 'ajout-rep': case 'vide-rep': case 'docs': case 'ajout-doc': case 'maj-doc': case 'agenda': case 'ajout-agenda': case 'deplcolle':
    include('ajaxediteur.php'); break;
  // Actions d'édition réservées aux professeurs de la matière concernée
  case 'progcolles': case 'ajout-progcolle': case 'cdt': case 'cdt-types': case 'ajout-cdt-type': case 'cdt-raccourcis': case 'ajout-cdt-raccourci': case 'ajout-transfert': case 'transferts': case 'prefsmatiere': case 'notescollesgestion':
    include('ajaxprofs.php'); break;
  // Répartition des actions : actions d'édition réservées aux professeurs de la matière concernée ou administrateurs
  case 'pages': case 'ajout-page': case 'matieres': case 'agenda-types': case 'ajout-agenda-types':
    include('ajaxprofsadmin.php'); break;
  // Répartition des actions : actions d'administration réservées aux administrateurs
  case 'ajout-matiere': case 'utilisateur': case 'utilisateurs': case'ajout-utilisateurs': case 'utilisateur-matiere': case 'utilisateurs-matieres': case 'groupes': case 'ajout-groupe': case 'planning': case 'prefsglobales':
    include('ajaxadmin.php'); break;
  // Actions autres
  case 'courriel': case 'prefsperso': case 'ajout-transdocs': case 'suppr-transdocs': case 'notescolles': case 'ajout-notescolles': case 'releve-colles': case 'dureecolles':
    include('ajaxautres.php');
}

// Sans action
exit('{"etat":"nok","message":"Aucune action effectuée"}');
?>
