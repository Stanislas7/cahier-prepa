<?php
// Sécurité
define('OK',1);
// Configuration
include('config.php');
// Fonctions
include('fonctions.php');

$mysqli = connectsql();

// Les messages sont stockés dans la base globale, table "messsages"
// Ils comportent une protection, sous la forme APLCEI +1 (A=admins)
// donc entre 1 et 64. 
// * Si p=64, le message n'est pas affiché
// * Si p<=32, les admins le voient quel que soit leur autorisation
// * Si p>32, seules les bonnes autorisations le voient 
// Exemple : 1 -> tout le monde voit. 
//          16 -> seuls les profs et admins voient
//          30 -> seuls les élèves et admins voient
//          32 -> seuls les admins voient
//          48 -> seuls les profs voient
//          56 -> seuls les comptes lycée voient
//          60 -> seuls les colleurs voient
//          62 -> seuls les élèves voient

// Page uniquement disponible si interface globale et utilisateur connecté
if ( !$interfaceglobale or !$autorisation )  {
    debut($mysqli,'Le blog de l\'admin','Cette page ne contient aucune information.',$autorisation,'blogcdp');
    $mysqli->close();
    fin();
}

// Les données non vides ne servent qu'à afficher l'icône de mode de lecture pour les profs et admins
$admin = $_SESSION['admin'];
if ( $edition = ( ( $autorisation == 5 ) || $admin ) )
  $donnees = array('action'=>'blog','matiere'=>0,'protection'=>0,'edition'=>0);

//////////////
//// HTML ////
//////////////
debut($mysqli,'Le blog de l\'admin de CdP',$message,$autorisation,'blogcdp',$donnees ?? false);
$mysqli->close();

// Écriture de $_SESSION['blogcdpok'] pour supprimer l'affichage sur la page d'accueil
$_SESSION['blogcdpok'] = true;

// Affichage de l'icône du choix de mode de lecture
if ( $edition )  {
  if ( $_SESSION['mode_lecture'] )  {
    $autorisation = $_SESSION['mode_lecture']-1;
    $admin = false;
    echo "\n\n  <div id=\"icones\">\n    <a class=\"icon-lecture mev\" title=\"Modifier le mode de lecture\"></a>\n  </div>\n\n";
  }
  else 
    echo "\n\n  <div id=\"icones\">\n    <a class=\"icon-lecture\" title=\"Modifier le mode de lecture\"></a>\n  </div>\n\n";
}

// Liste des autorisations pour l'affichage dans le message
$autorisations = array('invités','élèves','colleurs','lycée','professeurs','administrateurs');

// Connexion à la base de l'interface globale
$mysqli = connectsql(false,$interfaceglobale);

// Accès à un article spécifique
$requeteprotection = ( $admin ? 'protection <= 32' : "protection = 0 OR ( (protection-1)>>($autorisation-1) & 1 ) = 0" );
$requeteid = ( isset($_REQUEST['article']) && ctype_digit($id = $_REQUEST['article']) ) ? "AND id = $id" : '';
$resultat = $mysqli->query("SELECT id, titre, texte, protection, DATE_FORMAT(publi,'%w%Y%m%e %kh%i') AS date FROM messages WHERE ( $requeteprotection ) $requeteid AND publi < NOW() ORDER BY publi DESC");
$mysqli->close();
if ( $resultat->num_rows )  {
  $message = ( $autorisation > 4 ) ? 'Ces informations peuvent être fonction du type de compte. Vous pouvez cliquer sur l\'icône <span class="icon-lecture"></span> en haut à droite pour changer votre mode de lecture.'
                                    : 'Ces informations n\'ont pas été écrites par les professeurs de la classe.';
  echo "\n  <p>Cette page contient des informations écrites par le concepteur de Cahier de Prépa et administrateur de ce site. $message</p>\n";
  
  // Si plus de deux articles, liste des articles uniquement
  $affliste = ( $resultat->num_rows > 2 );
  while ( $r = $resultat->fetch_assoc() )  {
    $publi = format_date(substr($r['date'],0,9)).' à '.substr($r['date'],9);
    if ( $affliste )
      echo <<<FIN

  <article class="blog">
    <h2><a href="blogcdp?article=${r['id']}" title="Voir l'article">${r['titre']}</a></h2>
    <p class="publi">[Publié le $publi]</p>
  </article>

FIN;
    else  {
      $titre = ( $requeteid ) ? "<a href=\"blogcdp\" title=\"Retour au blog\">${r['titre']}</a>" : $r['titre'];
      $protection = array_keys(str_split(strrev(decbin( 64-$r['protection'] ))),1);
      $destinataires =  implode(', ', array_filter($autorisations, function($i) use($protection) { return in_array($i,$protection); },ARRAY_FILTER_USE_KEY) );
      echo <<<FIN

  <article class="blog">
    <h2>$titre</h2>
    <p class="publi">[&nbsp;Publié le $publi - Destinataires&nbsp;: $destinataires&nbsp;]</p>
${r['texte']}
  </article>

FIN;
    }
  }
  $resultat->free();
}
elseif ( $requeteid )
  echo "  <article><h2>Mauvais identifiant d'article.</h2><p><a href=\"blogcdp\">Revenir au blog</a></p></article>\n\n";
else
  echo "  <article><h2>Cette page est actuellement vide.</h2></article>\n\n";

fin($edition);
?>
