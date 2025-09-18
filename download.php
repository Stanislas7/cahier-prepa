<?php
// Sécurité
define('OK',1);
// Configuration
include('config.php');
// Fonctions
include('fonctions.php');

/////////////////////////////
// Téléchargement multiple //
/////////////////////////////
// La vérification des accès a été faite dans recup.php. Le code de vérification
// permet de ne pas la refaire
// Paramètres : zip (sans valeur), r (le répertoire), d (le document), verif
if ( isset($_REQUEST['zip']) && ctype_digit($id = $_REQUEST['d'] ?? '') && ctype_digit($rid = $_REQUEST['r'] ?? '') && isset($_REQUEST['verif'])  )  {
  $mysqli = connectsql();
  $resultat = $mysqli->query("SELECT nom, ext, id, lien, protection, dispo, rid, zip
                              FROM docs JOIN ( SELECT id AS rid, zip FROM reps ) AS r ON rid = parent WHERE id = $id");
  if ( $resultat->num_rows )  {
    $r = $resultat->fetch_assoc();
    $resultat->free();
    if ( $_REQUEST['verif'] != sha1("r${r['rid']}-d${r['id']}-${r['lien']}-${r['protection']}-${r['dispo']}-${r['zip']}-$mdp") )
      exit();
    
    // Récupération du chemin complet depuis le répertoire racine du zip
    if ( $rid != $r['rid'] )  {
      $resultat = $mysqli->query("SELECT GROUP_CONCAT( r.nom ORDER BY FIND_IN_SET(r.id,d.parents) SEPARATOR '/' ) 
                                  FROM ( SELECT * FROM docs WHERE id = $id ) AS d JOIN reps AS r ON FIND_IN_SET(r.id, d.parents)
                                  WHERE FIND_IN_SET(r.id, d.parents) > FIND_IN_SET($rid, d.parents)");
      $rep = $resultat->fetch_row()[0].'/';
      $resultat->free();
    }
    else 
      $rep = '';
    $mysqli->close();
    
    // Mise à disposition du fichier
    header('Content-Type: '.transforme_extension($r['ext'],1));
    $nom = $r['nom'] . ( $r['ext'] ? ".${r['ext']}" : '');
    // Correction de bug temporaire : dans la V11, des noms de documents ont été enregistrés
    // avec un htmlspecialchars qui encodait les guillemets simples.
    // Il faudra retirer htmlspecialchars_decode dans la V13.
    header("Content-Disposition: attachment; filename=\"".rawurlencode(htmlspecialchars_decode($rep.$nom)).'"');
    readfile("documents/${r['lien']}/$nom");
  }
  exit();
}

///////////////////////////////////
// Validation de la requête : id //
///////////////////////////////////

// Récupération du lien
if ( !isset($_REQUEST['id']) || !ctype_digit($id = $_REQUEST['id']) )
  exit('Mauvais paramètre d\'accès à cette page.');
$mysqli = connectsql();
// Les documents "non visibles" (protection 32), sauf pour les professeurs
// associés à la matière, ne sont pas accessibles.
$resultat = $mysqli->query("SELECT d.id, d.parents, d.nom, d.lien, d.ext, d.protection, d.matiere AS mid, m.nom AS mat, m.cle
                            FROM docs AS d LEFT JOIN matieres AS m ON d.matiere = m.id
                            WHERE d.id = $id AND IFNULL(docs,0) < 2 ".(( $autorisation == 5 ) ? "AND ( d.protection != 32 AND dispo < NOW() OR FIND_IN_SET(d.matiere,'${_SESSION['matieres']}') )" : 'AND d.protection != 32 AND dispo < NOW()'));
if ( $resultat->num_rows )  {
  $f = $resultat->fetch_assoc();
  $resultat->free();
}
else  {
  debut($mysqli,'Documents à télécharger','Mauvais paramètre d\'accès à cette page.',$autorisation,' ');
  $mysqli->close();
  fin();
}

/////////////////////////////
// Vérification de l'accès //
/////////////////////////////
acces($f['protection'],$f['mid'],$f['mat'] ? "Documents à télécharger - ${f['mat']}" : 'Documents à télécharger',$f['mat'] ? "docs?${f['cle']}" : 'docs',$mysqli);

$type = transforme_extension($f['ext'],1);
// Téléchargement forcé
if ( isset($_REQUEST['dl']) )
  $attachment = 'attachment';
// Récupération en ligne forcée (utile pour les vidéos/audios)
elseif ( isset($_REQUEST['inline']) )
  $attachment = 'inline';
// Visualisation forcée
elseif ( isset($_REQUEST['voir']) )  {
  if ( substr($type,0,5) == 'video' )  {
    debut($mysqli,($f['mat']) ? "Documents à télécharger - ${f['mat']}" : 'Documents à télécharger',$message,$autorisation,($f['mat']) ? "docs?${f['cle']}" : 'docs');
    $resultat = $mysqli->query("SELECT nom, taille, DATE_FORMAT(upload,'%w%Y%m%e') AS upload FROM docs WHERE id = $id");
    $r = $resultat->fetch_assoc();
    $resultat->free();
    $mysqli->close();
    $date = ucfirst(format_date($r['upload']));
    echo <<<FIN
  <article class="centre">
    <video src="download?id=$id&amp;inline" type="$type" controls>Votre navigateur n'affiche pas les fichiers vidéo en HTML5.</video>
  </article>
  
  <article>
    <h4>Détails de la vidéo</h4>
    <p><strong>Titre</strong>&nbsp;: ${r['nom']}</p>
    <p><strong>Taille</strong>&nbsp;: ${r['taille']}</p>
    <p><strong>Publication</strong>&nbsp;: $date</p>
    <p class="centre"><a class="icon-download" href="download?id=$id&amp;dl" title="Télécharger ce document"></a></p>
  </article>

FIN;
    fin();
  }
  elseif ( substr($type,0,5) == 'audio' )  {
    debut($mysqli,($f['mat']) ? "Documents à télécharger - ${f['mat']}" : 'Documents à télécharger',$message,$autorisation,($f['mat']) ? "docs?${f['cle']}" : 'docs');
    $resultat = $mysqli->query("SELECT nom, taille, DATE_FORMAT(upload,'%w%Y%m%e') AS upload FROM docs WHERE id = $id");
    $r = $resultat->fetch_assoc();
    $resultat->free();
    $mysqli->close();
    $date = ucfirst(format_date($r['upload']));
    echo <<<FIN
  <article class="centre">
    <audio src="download?id=$id&amp;inline" type="$type" controls>Votre navigateur n'affiche pas les fichiers audio en HTML5.</video>
  </article>
  
  <article>
    <h4>Détails du fichier audio</h4>
    <p><strong>Titre</strong>&nbsp;: ${r['nom']}</p>
    <p><strong>Taille</strong>&nbsp;: ${r['taille']}</p>
    <p><strong>Publication</strong>&nbsp;: $date</p>
    <p class="centre"><a class="icon-download" href="download?id=$id&amp;dl" title="Télécharger ce document"></a></p>
  </article>

FIN;
    fin();
  }
  elseif ( ( $f['ext'] == 'py' ) && !headers_sent() )  {
    header("Location: https://$domaine/basthon/?from=${chemin}download?id=$id");
    exit;
  }
  elseif ( ( $f['ext'] == 'sql' ) && !headers_sent() )  {
    header("Location: https://$domaine/basthon/?kernel=sql&from=${chemin}download?id=$id");
    exit;
  }
  elseif ( ( $f['ext'] == 'ml' ) && !headers_sent() )  {
    header("Location: https://$domaine/basthon/?kernel=ocaml&from=${chemin}download?id=$id");
    exit;
  }
  else
    $attachment = 'inline';
}
// Par défaut
else
  $attachment = ( in_array($f['ext'],array('pdf','jpg','jpeg','png','swf')) ? 'inline' : 'attachment' );

// Mise à disposition du fichier
header("Content-Type: $type");
$nom = $f['nom'] . ( $f['ext'] ? ".${f['ext']}" : '');
// Correction de bug temporaire : dans la V11, des noms de documents ont été enregistrés
// avec un htmlspecialchars qui encodait les guillemets simples.
// Il faudra retirer htmlspecialchars_decode dans la V13.
header("Content-Disposition: $attachment; filename=\"".rawurlencode(htmlspecialchars_decode($nom)).'"');
readfile("documents/${f['lien']}/$nom");
?>
