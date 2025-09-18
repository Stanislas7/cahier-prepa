<?php
// Sécurité
define('OK',1);
// Configuration
include('config.php');
// Fonctions
include('fonctions.php');

////////////////////////////////////////////
// Description des droits de modification //
////////////////////////////////////////////
// !!! L'édition n'est pas encore mise en place, mais possible
// dans la base de données (le champ édition existe dans rep).
////////////////////////////////////////////
// * Prof associé à la matière = propriétaire
//   -> $edition = 2
//   -> a accès à tout
// * Administrateur
//   -> n'a accès à rien s'il n'y a pas droit par ailleurs
// * Éditeur de rep : selon "edition" dans la table "rep",
//   (prof = hors matière, autres utilisateurs = avec matière associée)
//   -> $edition = 1
//   -> rep : ajout de doc uniquement
//   -> docs : renommage, suppression, mise à jour
// !!! L'édition n'est pas encore mise en place, mais possible
// dans la base de données (le champ édition existe dans rep).
// * Le mode lecture est visible uniquement des propriétaires et admins.
////////////////////////////////////////////
// * La protection d'un rep inclut la possibilité d'édition du rep.
// * Un doc peut être plus ou moins protégé en lecture que le rep.
////////////////////////////////////////////
// Paramètre zip : pour chaque répertoire, 
// 0 si zip non autorisé, 1 si zip autorisé pour les connectés,
// 2 si zip autorisé pour tous
////////////////////////////////////////////

//////////////////////////////////////////////////////
// Validation de la requête : répertoire ou matière //
//////////////////////////////////////////////////////

// Ordre d'affichage des documents (à supprimer avant d'analyser la requête)
$ordre = 'ORDER BY nom_nat ASC';
$ordrerep = 'ORDER BY nom ASC';
$ordreactuel = array('aa'=>'','ad'=>'','ca'=>'','cd'=>'');
if ( isset($_REQUEST['ordre']) )  {
  switch ($_REQUEST['ordre'])  {
    case 'alpha-inv':  $ordre = 'ORDER BY nom_nat DESC'; $ordrerep = 'ORDER BY nom DESC'; $ordreactuel['ad'] = 'actuel'; break;
    case 'chrono':     $ordre = 'ORDER BY docs.upload ASC'; $ordreactuel['ca'] = 'actuel'; break;
    case 'chrono-inv': $ordre = 'ORDER BY docs.upload DESC'; $ordreactuel['cd'] = 'actuel'; break;
    default: $ordreactuel['aa'] = 'actuel'; 
  }
  unset($_REQUEST['ordre']);
}
else
  $ordreactuel['aa'] = 'actuel'; 
  
// Clé spéciale pour le répertoire "Général"
if ( isset($_REQUEST['general']) )
  $_REQUEST['rep'] = "1";

// Récupération des données du répertoire demandé
$mysqli = connectsql();
// Requête non nulle : soit un numéro de répertoire, soit une clé de matière
// Les répertoires "non visibles" (protection 32), sauf pour les professeurs
// associés à la matière, ne sont pas accessibles (il ne faut pas que l'on
// obtienne "accès non autorisé" mais "mauvais paramètre").
// C'est bien JOIN et non LEFT JOIN ici (on ne garde pas les répertoires 
// des matières où la fonctionnalité est bloquée).
// Zip : 0 si non zipable, 1 si zipable par les connectés, 2 si par tous. 
$requetezip = ( $autorisation ) ? 'zip' : '(zip=2) AS zip';
$requete = 'SELECT r.id, r.nom, r.parent, r.parents, r.menu, '.$requetezip.', r.protection, r.edition, m.id AS mid, m.cle, m.nom AS matiere
            FROM reps AS r JOIN ( ( SELECT id, nom, cle FROM matieres WHERE docs < 2 ) UNION ( SELECT 0 AS id, \'\' AS nom, \'general\' AS cle ) ) AS m ON r.matiere = m.id';
$restriction = ( $autorisation == 5 ) ? "AND ( r.protection != 32 OR FIND_IN_SET(r.matiere,'${_SESSION['matieres']}') )" : 'AND r.protection != 32';
$requetezip = ( $autorisation ) ? '(zip>0) AS zip' : '(zip=2) AS zip';
if ( ctype_digit($rid = $_REQUEST['rep'] ?? '') )  {
  $resultat = $mysqli->query("$requete WHERE r.id = $rid $restriction");
  if ( $resultat->num_rows )  {
    $rep = $resultat->fetch_assoc();
    $resultat->free();
  }
}
elseif ( !empty($_REQUEST) )  {
  $resultat = $mysqli->query("$requete WHERE r.parent = 0 $restriction");
  if ( $resultat->num_rows )  {
    while ( $r = $resultat->fetch_assoc() )
      if ( isset($_REQUEST[$r['cle']]) )  {
        $rep = $r;
        $rid = $r['id'];
        break;
      }
    $resultat->free();
  }
}
// Pas d'argument : répertoire racine, contenant Général et les matières
else  {
  $rep = array('id'=>0,'mid'=>0,'parent'=>0, 'zip'=>0);
  $rid = 0;
  $titre = 'Documents à télécharger';
  $cle = 'docs';
}
// Si aucun répertoire trouvé
if ( !isset($rep) )  {
  debut($mysqli,'Documents à télécharger','Mauvais paramètre d\'accès à cette page.',$autorisation,' ');
  $mysqli->close();
  fin();
}
if ( !$rep['zip'] )
  $requetezip = '0 as zip';

/////////////////////////////
// Vérification de l'accès //
/////////////////////////////

// $edition vaut 1 (true) pour un simple éditeur, 2 pour un professeur associé à la matière.
// $editionjs sert à charger edition.js, doit être vrai si administrateur, si propriétaire
// La fonction acces() coupe l'exécution si elle n'est pas autorisée.
$admin = $_SESSION['admin'] ?? false;
$edition = $editionjs = $donnees = false;
$mode_lecture = 0;
if ( $rid )  {
  $titre = ( $rep['mid'] ) ? "Documents à télécharger - ${rep['matiere']}" : 'Documents à télécharger';
  $cle = ( $rep['mid'] ) ? "docs?${rep['cle']}" : 'docs?general';
  $edition = acces($rep['protection'],$rep['mid'],$titre,$cle,$mysqli,$rep['edition']);
  if ( $edition || $admin )  {
    $editionjs = true;
    $donnees = array('action'=>'docs','matiere'=>$rep['mid'],'protection'=>$rep['protection'],'edition'=>$rep['edition'],'css'=>'datetimepicker');
    $script = 'datetimepicker';
    // Variables pour les affichages des accès
    $textematiere = ( $rep['matiere'] ) ? ' associés à la matière' : '';
    $comptes = ( $rep['matiere'] ) ? array('invités','élèves','colleurs','comptes de type lycée','professeurs (même non associés à la matière)') : array('invités','élèves','colleurs','comptes de type lycée');
    $mode_lecture = $_SESSION['mode_lecture'];
  }
}
elseif ( ( $autorisation == 5 ) || $admin )  {
  $editionjs = true;
  $donnees = array('action'=>'docs','matiere'=>0,'protection'=>0,'edition'=>0);
  $mode_lecture = $_SESSION['mode_lecture'];
}

////////////
/// HTML ///
////////////
debut($mysqli,$titre,$message,$autorisation,$cle,$donnees);

// Répertoires parents pour le nom complet (pas de vérification de protection a priori)
if ( $rid )  {
  $resultat = $mysqli->query("SELECT GROUP_CONCAT(CONCAT('<a href=\"docs?rep=',id,'\">',nom,'</a>') ORDER BY parents SEPARATOR '&nbsp;/&nbsp;')
                              FROM reps WHERE FIND_IN_SET(id,'${rep['parents']},$rid')");
  // Remplacement du premier lien par le nom de matière
  $nom = preg_replace('/rep=\d+"/',"${rep['cle']}\"",$resultat->fetch_row()[0],1);
  $resultat->free();
}

// Affichage public sans édition, et page d'accueil pour tout le monde
if ( !$edition || $mode_lecture )  { 
    
  // Si mode lecture : on change l'autorisation. Rien de plus à faire.
  if ( $mode_lecture )  {
    $autorisation = $mode_lecture-1;
    $editionjs = true;
    echo "\n\n  <div id=\"icones\">\n    <a class=\"icon-lecture mev\" title=\"Modifier le mode de lecture\"></a>\n  </div>\n\n";
    if ( $rid && $rep['protection'] && ( !$autorisation || ( ( $rep['protection']-1 ) >> ( $autorisation-1 ) & 1 ) ) )  {
      echo "\n  <article><h2>Cette page n'est pas autorisée pour ce type d'utilisateur.</h2></article>\n\n";
      $mysqli->close();
      fin(true);
    }
  }
  // Nécessaire pour la page d'accueil
  elseif ( !$rid && ( ( $autorisation == 5 ) || $admin ) )  {
    $editionjs = true; 
    echo "\n\n  <div id=\"icones\">\n    <a class=\"icon-lecture\" title=\"Modifier le mode de lecture\"></a>\n  </div>\n\n";
  }

  // Si affichage de la première page : Répertoire général, matières
  if ( !$rid )  {
    echo "\n\n  <p id=\"parentsdoc\" class=\"topbarre\"><span class=\"icon-rep-open\"></span><span class=\"nom\">Répertoire racine</span>\n  </p>\n\n";
    if ( $autorisation == 5 )  {
      $requeteplus = ( strpos($_SESSION['matieres'],'c') ) ? 'OR (32-r.protection) & 20 AND FIND_IN_SET(r.matiere,\''.str_replace('c','',implode(',',array_filter(explode(',',$_SESSION['matieres']),function($v){return $v[0]=='c';}))).'\') ' : '';
      $resultat = $mysqli->query("SELECT r.id, r.nom, cle, IF( FIND_IN_SET(m.id,'${_SESSION['matieres']}') OR protection < 17 $requeteplus,0,1) AS protection, 0 AS zip
                                  FROM reps AS r LEFT JOIN matieres AS m ON matiere = m.id 
                                  WHERE parent = 0 AND protection != 32 ORDER BY ordre");
    }
    else  {
      $requete_matieres = ( $autorisation ? "AND FIND_IN_SET(matiere,'${_SESSION['matieres']}')" : '');
      $resultat = $mysqli->query('SELECT r.id, r.nom, cle, IF('.requete_protection($autorisation).",0,1) AS protection, 0 AS zip
                                  FROM reps AS r LEFT JOIN matieres AS m ON matiere = m.id 
                                  WHERE parent = 0 AND (r.id = 1 OR docs = 1) $requete_matieres AND protection != 32 ORDER BY ordre");
    }
  }
  // Cas général
  else  {
    $iconezip = ( $rep['zip'] ) ? "\n    <a class=\"icon-downloadrep\" title=\"Télécharger une partie de ce répertoire à sélectionner\" data-id=\"$rid\"></a>" : '';
    echo <<< FIN

  <p id="parentsdoc" class="topbarre">$iconezip
    <a class="icon-chronodesc ordre ${ordreactuel['cd']}" title="Classer les documents par ordre chronologique inversé"></a>
    <a class="icon-chronoasc ordre ${ordreactuel['ca']}" title="Classer les documents par ordre chronologique"></a>
    <a class="icon-alphadesc ordre ${ordreactuel['ad']}" title="Classer les documents par ordre alphabétique inversé"></a>
    <a class="icon-alphaasc ordre ${ordreactuel['aa']}" title="Classer les documents par ordre alphabétique"></a>
    <a href="docs" title="Revenir à l'accueil des documents"><span class="icon-rep-open"></span></a><span class="nom">$nom</span>
  </p>

FIN;
    $resultat = $mysqli->query('SELECT id, nom, IF('.requete_protection($autorisation).",0,1) AS protection, $requetezip FROM reps WHERE parent = $rid AND protection != 32 $ordrerep");
  }

  // Affichage du contenu du répertoire
  // Sous-répertoires
  if ( $nr = $resultat->num_rows )  {
    while ( $r = $resultat->fetch_assoc() )  {
      // Lien différent pour la page d'accueil
      $lien = ( $rid ) ? "rep=${r['id']}" : ( $r['cle'] ?: 'general');
      // Si protégé, pas de détails et lien que si utilisateur non connecté
      if ( $r['protection'] == 1 )  {
        if ( $autorisation )
          echo "\n  <p class=\"rep\"><span class=\"icon-rep\"></span><span class=\"icon-minilock\"></span><span class=\"nom\">${r['nom']}</span></p>";
        else
          echo "\n  <p class=\"rep\"><a href=\"?$lien\"><span class=\"icon-rep\"></span><span class=\"icon-minilock\"></span><span class=\"nom\">${r['nom']}</span></a></p>";
      }
      else  {
        $contenu = array();
        $resultatbis = $mysqli->query("SELECT id FROM reps WHERE parent = ${r['id']} AND protection != 32");
        if ( ($n = $resultatbis->num_rows) > 0 )
          $contenu[] = "$n répertoire".( ($n>1) ? 's' : '' );
        $resultatbis->free();
        $resultatbis = $mysqli->query("SELECT id FROM docs WHERE parent = ${r['id']} AND protection != 32 AND dispo < NOW()");
        if ( ($n = $resultatbis->num_rows) > 0 )
          $contenu[] = "$n document".( ($n>1) ? 's' : '' );
        $resultatbis->free();
        $contenu = ( $contenu ) ? implode(', ',$contenu) : 'vide';
        // $r['zip'] vaut 1 si le téléchargement est autorisé sur le répertoire 
        // parent (icône présente) et dans le répertoire en question.
        // L'id du répertoire est nécessaire pour le formulaire javascript.
        $datazip = ( $r['zip'] ) ? " data-id=\"${r['id']}\"" : '';
        echo "\n  <p class=\"rep\"$datazip><span class=\"repcontenu\">($contenu)</span> <a href=\"?$lien\"><span class=\"icon-rep\"></span><span class=\"nom\">${r['nom']}</span></a></p>";
      }
    }
    $resultat->free();
  }

  // Documents
  $resultat = $mysqli->query('SELECT id, nom, taille, DATE_FORMAT(GREATEST(upload,dispo),\'%d/%m/%Y\') AS upload, ext, IF('.requete_protection($autorisation).",0,1) AS protection
                              FROM docs WHERE parent = $rid AND protection != 32 AND dispo < NOW() $ordre");
  if ( $nd = $resultat->num_rows )  {
    while ( $r = $resultat->fetch_assoc() )  {
      $icone = transforme_extension($r['ext']);
      // Si protégé, pas de détails et lien que si utilisateur non connecté
      if ( $r['protection'] == 1 )  {
        if ( $autorisation )
          echo "\n  <p class=\"doc\"><span class=\"$icone\"></span><span class=\"icon-minilock\"></span><span class=\"nom\">${r['nom']}</span></p>";
        else
          echo "\n  <p class=\"doc\"><a href=\"download?id=${r['id']}\"><span class=\"$icone\"></span><span class=\"icon-minilock\"></span><span class=\"nom\">${r['nom']}</span></a></p>";
      }
      else  {
        // $datazip permet le téléchargement, si le répertoire parent l'autorise
        $datazip = ( $rep['zip'] ) ? " data-id=\"${r['id']}\"" : '';
        $type = substr(transforme_extension($r['ext'],1), 0,5);
        $lienvoir = '';
        if ( $type == 'video' )
          $lienvoir = "<a class=\"icon-play\" href=\"download?id=${r['id']}&amp;voir\" title=\"Voir directement ici la vidéo\"></a>&nbsp;";
        elseif ( $type == 'audio' )
          $lienvoir = "<a class=\"icon-play\" href=\"download?id=${r['id']}&amp;voir\" title=\"Écouter directement ici l'audio\"></a>&nbsp;";
        elseif ( $r['ext'] == 'py' )
          $lienvoir = "<a class=\"icon-play\" href=\"download?id=${r['id']}&amp;voir\" title=\"Tester directement ici le fichier Python (dans Basthon)\"></a>&nbsp;";
        elseif ( $r['ext'] == 'sql' )
          $lienvoir = "<a class=\"icon-play\" href=\"download?id=${r['id']}&amp;voir\" title=\"Tester directement ici le fichier SQL (dans Basthon)\"></a>&nbsp;";
        echo "\n  <p class=\"doc\"$datazip><span class=\"docdonnees\">(${r['ext']}, ${r['upload']}, ${r['taille']})</span> $lienvoir<a href=\"download?id=${r['id']}\"><span class=\"$icone\"></span><span class=\"nom\">${r['nom']}</span></a></p>";
      }
    }
    $resultat->free();
  }
  
  // Répertoire vide
  if ( $nr+$nd == 0 )
    echo "\n  <h2>Ce répertoire est vide.</h2>\n";
    
  // Pour les répertoires de premier niveau comportant au moins 1 sous-répertoire, recherche des fichiers récents
  elseif ( $nr )  {
    // Page d'accueil
    if ( !$rid )  {
      if ( $autorisation )
        $requete = "( FIND_IN_SET(d.matiere,'${_SESSION['matieres']}') ".( ( $autorisation == 5 ) ? "OR d.protection < 17 ".str_replace('r.','d.',$requeteplus) : 'AND '.requete_protection($autorisation,'d.') ).' ) AND';
      else
        $requete = 'd.protection = 0 AND';
      $resultat = $mysqli->query("SELECT d.id, GROUP_CONCAT( r.nom ORDER BY FIND_IN_SET(r.id,d.parents) SEPARATOR '/' ) AS chemin, d.nom, d.taille, 
                                  DATE_FORMAT(GREATEST(upload,dispo),'%d/%m/%Y') AS upload, GREATEST(upload,dispo) AS ordre, ext
                                  FROM docs AS d LEFT JOIN reps AS r ON FIND_IN_SET(r.id,d.parents) 
                                  WHERE $requete dispo < NOW() GROUP BY d.id HAVING ordre > DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY ordre DESC");
    }
    // Répertoire réel
    else
      $resultat = $mysqli->query("SELECT d.id, GROUP_CONCAT( r.nom ORDER BY FIND_IN_SET(r.id,d.parents) SEPARATOR '/' ) AS chemin, d.nom, taille, 
                                  DATE_FORMAT(GREATEST(upload,dispo),'%d/%m/%Y') AS upload, GREATEST(upload,dispo) AS ordre, ext
                                  FROM docs AS d LEFT JOIN reps AS r ON FIND_IN_SET(r.id,d.parents) 
                                  WHERE FIND_IN_SET($rid,d.parents) AND ".requete_protection($autorisation,'d.').' AND dispo < NOW()
                                  GROUP BY d.id HAVING ordre > DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY ordre DESC');
    if ( $resultat->num_rows )  {
      echo "\n\n  <h3>Documents récents</h3>\n";
      while ( $r = $resultat->fetch_assoc() )  {
        $icone = transforme_extension($r['ext']);
        $type = substr(transforme_extension($r['ext'],1), 0,5);
        $ext = $r['ext'] ?: 'sans ext';
        $lienvoir = '';
        if ( $type == 'video' )
          $lienvoir = "<a class=\"icon-play\" href=\"download?id=${r['id']}&amp;voir\" title=\"Voir directement ici la vidéo\"></a>&nbsp;";
        elseif ( $type == 'audio' )
          $lienvoir = "<a class=\"icon-play\" href=\"download?id=${r['id']}&amp;voir\" title=\"Écouter directement ici l'audio\"></a>&nbsp;";
        elseif ( $ext == 'py' )
          $lienvoir = "<a class=\"icon-play\" href=\"download?id=${r['id']}&amp;voir\" title=\"Tester directement ici le fichier Python (dans Basthon)\"></a>&nbsp;";
        elseif ( $ext == 'sql' )
          $lienvoir = "<a class=\"icon-play\" href=\"download?id=${r['id']}&amp;voir\" title=\"Tester directement ici le fichier SQL (dans Basthon)\"></a>&nbsp;";
        echo "\n  <p class=\"doc\"><span class=\"docdonnees\">($ext, ${r['upload']}, ${r['taille']})</span> $lienvoir<a href=\"download?id=${r['id']}\"><span class=\"$icone\"></span><span class=\"nom\">${r['chemin']}/${r['nom']}</span></a></p>";
      }
      $resultat->free();
    }
  }
  
  if ( $autorisation == 2 )  {
    echo <<<FIN
  <div id="aide-download">
    <p>Certains professeurs sont modernes et acceptent volontiers de vous laisser télécharger facilement leurs documents. Mais ce n'est pas pour cela que vous avez le droit d'en faire tout ce que vous voulez&nbsp;! Soyez sympas, et s'il-vous-plaît&nbsp;:</p>
    <ul>
      <li>Ne mettez pas des documents massivement sur internet (site web perso, réseaux sociaux, cloud). Les documents que vos professeurs produisent pour vous sont en lien  avec le cours, et si on les sort de ce contexte ils peuvent être mal utilisés (être mal compris, travaillés par des personnes qui n'ont pas le bon niveau...).</li>
      <li>Ne mettez pas en difficulté vos professeurs : s'il vous fournissent des documents qui viennent d'ailleurs, cela reste possible à l'échelle d'une classe mais devient complètement illégal à grande échelle. Ils pourraient être tenus responsables d'un partage trop large de votre part.</li>
      <li>Ne cassez pas la pédagogie qu'attendent les prochains élèves. Typiquement, donner des sujets ou des corrigés de devoirs ou d'interrogation en avance, si ce n'est pas prévu par le professeur, peut complètement casser ce qui était prévu. Et ce n'est alors pas du tout aider vos successeurs que de faire cela, au contraire. Faites confiance à vos professeurs, ils savent ce qu'ils font (s'ils ne donnent pas d'annales de leurs devoirs ou interrogations, c'est volontaire).</li>
    </ul>
    <p>Certains documents, téléchargeables séparément, peuvent ne pas se retrouver dans le fichier zip que vous allez générer. Ce n'est dans ce cas pas une erreur du site, c'est une volonté de votre professeur.</p>
  </div>
FIN;
  }
}

// Affichage professeur éditeur
else  {
  // Icône de préférences seulement pour les répertoires non racine de matière
  $iconeprefs = ( $rep['parent'] ) ? "\n    <a class=\"icon-prefsrep formulaire\" title=\"Modifier ce répertoire\"></a>" : '';
  echo <<< FIN

  <div id="icones" data-id="$rid" data-nom="${rep['nom']}" data-protection="${rep['protection']}" data-parent="${rep['parent']}" data-menu="${rep['menu']}" data-zip="${rep['zip']}">
    <a class="icon-ajoutedoc formulaire" title="Ajouter un document dans ce répertoire"></a>
    <a class="icon-ajouterep formulaire" title="Ajouter un sous-répertoire"></a>
    <a class="icon-viderep formulaire" title="Vider partiellement ce répertoire"></a>
    <a class="icon-lockrep formulaire" title="Modifier l'accès à ce répertoire"></a>$iconeprefs
    <a class="icon-lecture" title="Modifier le mode de lecture"></a>
    <a class="icon-aide" title="Aide pour les modifications des répertoires et documents"></a>
  </div>

  <p id="parentsdoc" class="topbarre">
    <a class="icon-downloadrep formulaire" title="Télécharger une partie de ce répertoire à sélectionner" data-zip="${rep['zip']}"></a>
    <a class="icon-chronodesc ordre ${ordreactuel['cd']}" title="Classer les documents par ordre chronologique inversé"></a>
    <a class="icon-chronoasc ordre ${ordreactuel['ca']}" title="Classer les documents par ordre chronologique"></a>
    <a class="icon-alphadesc ordre ${ordreactuel['ad']}" title="Classer les documents par ordre alphabétique inversé"></a>
    <a class="icon-alphaasc ordre ${ordreactuel['aa']}" title="Classer les documents par ordre alphabétique"></a>
    <a href="docs" title="Revenir à l'accueil des documents"><span class="icon-rep-open"></span></a><span class="nom">$nom</span>
  </p>

FIN;

  // Affichage du répertoire et de son contenu
  // Sous-répertoires
  $resultat = $mysqli->query("SELECT id, nom, parent, menu, protection, zip FROM reps WHERE parent = $rid $ordrerep");
  if ( $nr = $resultat->num_rows )  {
    while ( $r = $resultat->fetch_assoc() )  {
      // Cas particulier : toujours une icône locktotal si répertoire invisible
      if ( ( $p = $r['protection'] ) == 32 )  {
        $iconeprotection = "<span class=\"icon-locktotal affichable\" data-title=\"<strong>Répertoire invisible</strong>.<br> Ce repertoire n'est visible que par les professeurs$textematiere.\"></span>";
        $classdispo = ' nodispo';
        $visible = '<a class="icon-montre" title="Afficher ce document comme le répertoire parent"></a><a class="icon-cache" style="display:none;" title="Rendre invisible ce document"></a>';
      }  
      // Affichage de la protection si différente de celle de la page
      else  {
        $classdispo = '';
        $visible = '<a class="icon-montre" style="display:none;" title="Afficher ce document comme le répertoire parent"></a><a class="icon-cache" title="Rendre invisible ce document"></a>';
        if ( $p != $rep['protection'] )  {
          if ( $p )  {
            $texte = ( $p == 1 ) ? "tous les utilisateurs connectés"
                                   : 'les '.preg_replace('/,([^,]+)$/',' et$1',implode(', les ',array_filter($comptes,function($a) use($p) { return !(($p-1)>>$a & 1); },ARRAY_FILTER_USE_KEY)));
            $iconeprotection = ( !$rep['protection'] || ( $rep['protection']-1 == ( $p-1 & $rep['protection']-1 ) ) )
              ? "<span class=\"icon-lock affichable\" data-title=\"<strong>La protection de ce répertoire est plus restrictive que celle du répertoire parent</strong> : en plus des professeurs$textematiere, ce répertoire est visible uniquement par $texte.\"></span>"
              : "<span class=\"icon-lock mev affichable\" data-title=\"<strong>La protection de ce répertoire est moins restrictive que celle du répertoire parent</strong> : en plus des professeurs$textematiere, ce répertoire est visible par $texte, à condition de connaître ou récupérer le lien correspondant.\"></span>";
          }
          else
            $iconeprotection = '<span class="icon-lock mev affichable" data-title="Contrairement au répertoire parent, <strong>ce répertoire est visible de tous les utilisateurs</strong>, même sans connexion, à condition de connaître ou récupérer le lien correspondant."></span>';
        }
        else
          $iconeprotection = '';
      }
      $contenu = array();
      $resultatbis = $mysqli->query("SELECT id FROM reps WHERE parent = ${r['id']} AND protection != 32");
      if ( ($n = $resultatbis->num_rows) > 0 )
        $contenu[] = "$n répertoire".( ($n>1) ? 's' : '' );
      $resultatbis->free();
      $resultatbis = $mysqli->query("SELECT id FROM docs WHERE parent = ${r['id']} AND protection != 32");
      if ( ($n = $resultatbis->num_rows) > 0 )
        $contenu[] = "$n document".( ($n>1) ? 's' : '' );
      $resultatbis->free();
      $contenu = ( $contenu ) ? implode(', ',$contenu) : 'vide';
      echo <<<FIN

  <p class="rep$classdispo" data-action="reps" data-id="${r['id']}" data-protection="${r['protection']}" data-parent="${r['parent']}" data-menu="${r['menu']}" data-zip="${r['zip']}">
    <a class="icon-prefsrep formulaire" title="Renommer ou déplacer ce répertoire"></a>
    <a class="icon-ajoutedoc formulaire" title="Ajouter un document dans ce répertoire"></a>
    <a class="icon-lockrep formulaire" title="Modifier l'accès à ce répertoire"></a>
    $visible
    <a class="icon-supprime" title="Supprimer ce répertoire et son contenu"></a>
    <span class="repcontenu">($contenu)</span> 
    <a href="?rep=${r['id']}"><span class="icon-rep"></span></a>$iconeprotection<span class="nom editable" data-champ="nom" data-id="${r['id']}">${r['nom']}</span>
  </p>

FIN;
    }
    $resultat->free();
  }

  // Documents
  $resultat = $mysqli->query("SELECT id, nom, taille, DATE_FORMAT(upload,'%d/%m/%Y') AS upload, ext, protection,
                              IF(dispo>NOW(),1,0) AS affdiff, DATE_FORMAT(dispo,'%d/%m/%Y %kh%i') AS dispo, DATE_FORMAT(dispo,'%w%Y%m%e') AS dispo2
                              FROM docs WHERE parent = $rid $ordre");
  if ( $nd = $resultat->num_rows )  {
    while ( $r = $resultat->fetch_assoc() )  {
      // Cas particulier : toujours une icône locktotal si répertoire invisible
      if ( ( $p = $r['protection'] ) == 32 )
        $iconeprotection = "<span class=\"icon-locktotal affichable\" data-title=\"<strong>Document invisible</strong>.<br> Ce document n'est visible que par les professeurs$textematiere.\"></span>";
      // Affichage de la protection si différente de celle de la page
      elseif ( $p != $rep['protection'] )  {
        if ( $p )  {
          $texte = ( $p == 1 ) ? "tous les utilisateurs connectés"
                                 : 'les '.preg_replace('/,([^,]+)$/',' et$1',implode(', les ',array_filter($comptes,function($a) use($p) { return (32-$p)>>$a & 1; },ARRAY_FILTER_USE_KEY)));
          $iconeprotection = ( !$rep['protection'] || ( $rep['protection']-1 == ( $p-1 & $rep['protection']-1 ) ) )
            ? "<span class=\"icon-lock affichable\" data-title=\"<strong>La protection de ce document est plus restrictive que celle du répertoire</strong> : en plus des professeurs$textematiere, ce document est visible uniquement par $texte.\"></span>"
            : "<span class=\"icon-lock mev affichable\" data-title=\"<strong>La protection de ce document est moins restrictive que celle du répertoire</strong> : en plus des professeurs$textematiere, ce document est visible par $texte. Des utilisateurs n'ayant pas accès au répertoire peuvent donc télécharger ce document, par exemple sur la page des <span class='icon-recent'></span>&nbsp;derniers contenus.\"></span>";
        }
        else
          $iconeprotection = '<span class="icon-lock mev affichable" data-title="Contrairement au répertoire, <strong>ce document est visible de tous les utilisateurs</strong>, même sans connexion. Des utilisateurs n\'ayant pas accès au répertoire peuvent télécharger ce document, par exemple sur la page des <span class=\'icon-recent\'></span>&nbsp;derniers contenus."></span>';
      }
      else
        $iconeprotection = '';
      $icone = transforme_extension($r['ext']);
      $type = substr(transforme_extension($r['ext'],1), 0,5);
      $ext = $r['ext'] ?: 'sans ext';
      $recent = ( $r['affdiff'] ) ? '<a class="icon-recent mev formulaire" title="Ce document ne s\'affichera que le '.format_date($r['dispo2']).' à '.substr($r['dispo'],11).'"></a>' : '<a class="icon-recent formulaire" title="Régler un affichage différé"></a>';
      $classdispo = ( $r['affdiff'] || ( $p == 32 ) ) ? ' nodispo' : '';
      $visible = ( $p == 32 ) ? '<a class="icon-montre" title="Afficher ce document comme le répertoire parent"></a><a class="icon-cache" style="display:none;" title="Rendre invisible ce document"></a>'
                              : '<a class="icon-montre" style="display:none;" title="Afficher ce document comme le répertoire parent"></a><a class="icon-cache" title="Rendre invisible ce document"></a>';
      if ( !$r['affdiff'] )
        $r['dispo'] = 0;
      $lienvoir = '';
      if ( $type == 'video' )
        $lienvoir = "<a class=\"icon-play\" href=\"download?id=${r['id']}&amp;voir\" title=\"Voir directement ici la vidéo\"></a>&nbsp;";
      elseif ( $type == 'audio' )
        $lienvoir = "<a class=\"icon-play\" href=\"download?id=${r['id']}&amp;voir\" title=\"Écouter directement ici l'audio\"></a>&nbsp;";
      elseif ( $ext == 'py' )
        $lienvoir = "<a class=\"icon-play\" href=\"download?id=${r['id']}&amp;voir\" title=\"Tester directement ici le fichier Python (dans Basthon)\"></a>&nbsp;";
      elseif ( $ext == 'sql' )
        $lienvoir = "<a class=\"icon-play\" href=\"download?id=${r['id']}&amp;voir\" title=\"Tester directement ici le fichier SQL (dans Basthon)\"></a>&nbsp;";
      echo <<<FIN

  <p class="doc$classdispo" data-id="${r['id']}" data-protection="${r['protection']}" data-dispo="${r['dispo']}">
    <a class="icon-prefsdoc formulaire" title="Renommer ou déplacer ce document"></a>
    <a class="icon-actualise formulaire" title="Mettre à jour le fichier"></a>
    $recent
    <a class="icon-lockdoc formulaire" title="Modifier l'accès à ce document"></a>
    $visible
    <a class="icon-supprime" title="Supprimer ce document"></a>
    <span class="docdonnees">($ext, ${r['upload']}, ${r['taille']})</span>
    $lienvoir<a href="download?id=${r['id']}" title="Télécharger ce document"><span class="$icone"></span></a>$iconeprotection<span class="nom editable" data-champ="nom" data-id="${r['id']}">${r['nom']}</span>
  </p>
  
FIN;
    }
    $resultat->free();
    
    // Documents récents
    $resultat = $mysqli->query("SELECT d.id, GROUP_CONCAT( r.nom ORDER BY FIND_IN_SET(r.id,d.parents) SEPARATOR '/' ) AS chemin, d.nom, taille, 
                                DATE_FORMAT(GREATEST(upload,dispo),'%d/%m/%Y') AS upload, GREATEST(upload,dispo) AS ordre, ext
                                FROM docs AS d LEFT JOIN reps AS r ON FIND_IN_SET(r.id,d.parents) 
                                WHERE FIND_IN_SET($rid,d.parents) AND ".requete_protection($autorisation,'d.').' AND dispo < NOW()
                                GROUP BY d.id HAVING ordre > DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY ordre DESC');
    if ( $resultat->num_rows )  {
      echo "\n  <h3>Documents récents</h3>\n";
      while ( $r = $resultat->fetch_assoc() )  {
        $icone = transforme_extension($r['ext']);
        $type = substr(transforme_extension($r['ext'],1), 0,5);
        $ext = $r['ext'] ?: 'sans ext';
        $lienvoir = '';
        if ( $type == 'video' )
          $lienvoir = "<a class=\"icon-play\" href=\"download?id=${r['id']}&amp;voir\" title=\"Voir directement ici la vidéo\"></a>&nbsp;";
        elseif ( $type == 'audio' )
          $lienvoir = "<a class=\"icon-play\" href=\"download?id=${r['id']}&amp;voir\" title=\"Écouter directement ici l'audio\"></a>&nbsp;";
        elseif ( $ext == 'py' )
          $lienvoir = "<a class=\"icon-play\" href=\"download?id=${r['id']}&amp;voir\" title=\"Tester directement ici le fichier Python (dans Basthon)\"></a>&nbsp;";
        elseif ( $ext == 'sql' )
          $lienvoir = "<a class=\"icon-play\" href=\"download?id=${r['id']}&amp;voir\" title=\"Tester directement ici le fichier SQL (dans Basthon)\"></a>&nbsp;";
        echo "\n  <p class=\"doc\"><span class=\"docdonnees\">($ext, ${r['upload']}, ${r['taille']})</span> $lienvoir<a href=\"download?id=${r['id']}\"><span class=\"$icone\"></span><span class=\"nom\">${r['chemin']}/${r['nom']}</span></a></p>\n";
      }
      $resultat->free();
    }
  }
  // Répertoire vide
  if ( $nr+$nd == 0 )
    echo "\n  <h2>Ce répertoire est vide.</h2>\n";

  // Select sur les répertoires (pour les déplacements)
  function liste($rid,$n)  {
    $resultat = $GLOBALS['mysqli']->query("SELECT id, nom, parents FROM reps WHERE parent = $rid ORDER BY nom");
    while ( $r = $resultat->fetch_assoc() )  {
      $GLOBALS['select_reps'] .= "\n        <option value=\"${r['id']}\" data-parents=\"${r['parents']},${r['id']},\">".str_repeat('&rarr;',$n)."${r['nom']}</option>";
      liste($r['id'],$n+1);
    }
    $resultat->free();
  }
  $select_reps = '';
  $resultat = $mysqli->query("SELECT r.id, r.nom, parents FROM reps AS r LEFT JOIN matieres AS m ON m.id = r.matiere WHERE r.parent = 0 AND FIND_IN_SET(r.matiere,'${_SESSION['matieres']}') ORDER BY m.ordre");
  while ( $r = $resultat->fetch_assoc() )  {
    $select_reps .= "\n        <option value=\"${r['id']}\" data-parents=\"${r['parents']},${r['id']},\">${r['nom']}</option>";
    liste($r['id'],1);
  }
  $resultat->free();
                              
  // Taille maximale de fichier (pour l'aide)
  $taille = min(ini_get('upload_max_filesize'),ini_get('post_max_size'));
  if ( stristr($taille,'m') )
    $taille = substr($taille,0,-1)*1048576;
  elseif ( stristr($taille,'k') )
    $taille = substr($taille,0,-1)*1024;
  $taille = ( $taille < 1048576 ) ? intval($taille/1024).'&nbsp;ko' : intval($taille/1048576).'&nbsp;Mo';

  // Aide et formulaire d'ajout
?>

  <form id="form-ajoutedoc" data-action="ajout-doc">
    <h3 class="edition">Ajouter des documents</h3>
    <p>Vous pouvez ici ajouter un ou plusieurs documents, au sein du répertoire <em></em>. Sélectionnez plusieurs fichiers pour les envoyer simultanément. N'oubliez pas de les renommer s'ils n'ont pas un nom explicite !</p>
    <p class="ligne"><label for="fichier[]">Fichiers&nbsp;: </label><input type="file" name="fichier[]" multiple></p>
    <p class="ligne"><label for="protection">Accès&nbsp;: </label><select name="protection[]" multiple data-val32="Document invisible"></select></p>
    <p class="ligne"><label for="affdiff">Affichage différé&nbsp;: </label><input type="checkbox" class="nonbloque" name="affdiff" value="1"></p>
    <p class="ligne"><label for="dispo">Date de disponibilité &nbsp;: </label><input type="text" name="dispo" value="" size="15"></p>
    <p>Les documents dans ce répertoire sont toujours visibles par les professeurs<?php echo $textematiere; ?>.</p>
  </form>

  <form id="form-ajouterep" data-action="ajout-rep">
    <h3 class="edition">Ajouter un répertoire</h3>
    <p class="ligne"><label for="nom">Nom&nbsp;: </label><input type="text" name="nom" value="" size="50" placeholder="Nom du répertoire"></p>
    <p class="ligne"><label for="menurep">Affichage du répertoire dans le menu&nbsp;: </label><input type="checkbox" name="menurep" value="1"></p>
    <p class="ligne"><label for="protection">Accès&nbsp;: </label><select name="protection[]" multiple data-val32="Répertoire invisible"></select></p>
    <p class="ligne"><label for="download">Autoriser le téléchargement&nbsp;: </label><select name="download">
      <option value="0">Par personne sauf vous</option><option value="1">Par les utilisateurs connectés</option><option value="2">Par tout visiteur du site</option>
    </select></p>
    <p>Ce répertoire est obligatoirement visible par les professeurs<?php echo $textematiere; ?>.</p>
    <p>Quel que soit le choix pour l'autorisation de téléchargement, seuls les documents autorisés sont téléchargeables réellement.</p>
  </form>

  <form id="form-viderep" data-action="vide-rep">
    <h3 class="edition">Supprimer partiellement le contenu d'un répertoire</h3>
    <p class="ligne"><label for="docscaches">Cocher toutes les ressources cachées&nbsp;: </label><input type="checkbox" class="nonbloque" name="docscaches" value="1"></p>
    <table>
      <thead>
        <tr><th>Nom</th><th class="icones"><a class="icon-cocher" title="Tout cocher"></a></th></tr>
      </thead>
      <tbody>
      </tbody>
    </table>
    <p>Les sous-répertoires et documents cochés seront supprimés si vous validez cette action en cliquant sur le bouton&nbsp;<span class="icon-ok"></span>. Attention, cette suppression sera définitive. Il ne sera pas demandé de confirmation.</p>
  </form>

  <form id="form-downloadrep" data-action="download-rep">
    <h3 class="edition">Télécharger le contenu d'un répertoire</h3>
    <p class="zip0">Ce répertoire n'est pas téléchargeable, sauf par quelques privilégiés : seuls les professeurs associés à la matière comme vous voient l'icône de téléchargement.</p>
    <p class="zip1">Ce répertoire est téléchargeable par toute personne connectée (invités, élèves, colleurs, comptes de type lycée), mais les documents protégés restent accessibles uniquement aux personnes qui y ont accès. En particulier, les documents invisibles ne sont pas téléchargeables.</p>
    <p class="zip2">Ce répertoire est téléchargeable par toute personne visitant le site, connectée ou non&nbsp;; mais les documents protégés restent accessibles uniquement aux personnes qui y ont accès.</p>
    <p class="ligne"><label for="docscaches">Ne pas inclure les documents cachés&nbsp;: </label><input type="checkbox" class="nonbloque" name="docscaches" value="1"></p>
    <table>
      <thead>
        <tr><th>Nom</th><th>Téléchargeable par</th><th class="icones"><a class="icon-cocher" title="Tout cocher"></a></th></tr>
      </thead>
      <tbody>
      </tbody>
    </table>
  </form>

  <form id="form-prefsrep" data-action="reps">
    <h3 class="edition">Modifier le répertoire <em></em></h3>
    <p class="ligne"><label for="nom">Nom&nbsp;: </label><input type="text"name="nom" value="" size="50" placeholder="Nom du répertoire"></p>
    <p class="ligne"><label for="menurep">Affichage du répertoire dans le menu&nbsp;: </label><input type="checkbox" name="menurep" value="1"></p>
    <hr>
    <p class="ligne"><label for="parent">Déplacer&nbsp;: </label>
      <select name="parent">
        <option value="0">Ne pas déplacer</option><?php echo $select_reps; ?>
      </select>
    </p>
  </form>

  <form id="form-lockrep" data-action="reps">
    <h3 class="edition">Modifier l'accès au répertoire <em></em></h3>
    <p class="ligne"><label for="protection">Accès&nbsp;: </label><select name="protection[]" multiple data-val32="Répertoire invisible"></select></p>
    <p class="ligne"><label for="download">Autoriser le téléchargement&nbsp;: </label><select name="download">
      <option value="0">Par personne sauf vous</option><option value="1">Par les utilisateurs connectés</option><option value="2">Par tout visiteur du site</option>
    </select></p>
    <p class="ligne"><label for="propage">Propager ces choix d'accès à chaque document/sous-répertoire&nbsp;: </label><input type="checkbox" name="propage" value="1"></p>
    <p>Ce répertoire est obligatoirement visible par les professeurs<?php echo $textematiere; ?>.</p>
    <p>Quel que soit le choix pour l'autorisation de téléchargement, seuls les documents autorisés sont téléchargeables réellement.</p>
  </form>

  <form id="form-lockdoc">
    <h3 class="edition">Modifier l'accès au document <em></em></h3>
    <p class="ligne"><label for="protection">Accès&nbsp;: </label><select name="protection[]" multiple data-val32="Document invisible"></select></p>
    <p>Ce document est obligatoirement visible par les professeurs<?php echo $textematiere; ?>.</p>
  </form>

  <form id="form-recent">
    <h3 class="edition">Différer l'affichage du document <em></em></h3>
    <p class="ligne"><label for="protection">Accès&nbsp;: </label><select name="protection[]" multiple data-val32="Document invisible"></select></p>
    <p class="ligne"><label for="dispo">Date de disponibilité&nbsp;: </label><input type="text" name="dispo" size="15" placeholder="Optionnel"></p>
    <p>Laissez la case ci-dessus vide pour rendre ne pas utiliser l'affichage différé. L'affichage différé n'est pas possible si vous sélectionnez «&nbsp;Document invisible&nbsp;».</p>
    <p>Ce document est obligatoirement visible par les professeurs<?php echo $textematiere; ?>.</p>
  </form>

  <form id="form-actualise" data-action="maj-doc">
    <h3 class="edition">Mettre à jour le document <em></em></h3>
    <p class="ligne"><label for="fichier">Mettre à jour&nbsp;: </label><input type="file" name="fichier"></p>
    <p class="ligne"><label for="publi">Publier en tant que mise à jour&nbsp;: </label><input type="checkbox" class="nonbloque" name="publi" value="1" checked></p>
  </form>

  <form id="form-prefsdoc">
    <h3 class="edition">Renommer/déplacer le document <em></em></h3>
    <p class="ligne"><label for="nom">Nom&nbsp;: </label><input type="text" name="nom" value="" size="50" placeholder="Nom du document"></p>
    <hr>
    <p class="ligne"><label for="parent">Déplacer&nbsp;: </label>
      <select name="parent">
        <option value="0">Ne pas déplacer</option><?php echo str_replace("\"$rid\"","\"$rid\" disabled",$select_reps); ?>
      </select>
    </p>
  </form>

  <div id="aide-docs">
    <h3>Aide et explications</h3>
    <p>Il est possible ici de modifier le contenu des répertoires et les propriétés des répertoires et des documents présents. Vous avez la possibilité de modifier les documents non associés à une matière (répertoire «&nbsp;Général&nbsp;») et les documents dans les matières qui vous sont associées. Le réglage de ces matières s'effectue à la page de <a href="utilisateurs-matieres">gestion des associations de matières</a>.</p>
    <p>Les noms des répertoires et documents contenus dans le répertoire affiché sur cette page sont modifiables directement, en cliquant sur le bouton <span class="icon-edite"></span> situé dans la case encadrée de pointillés.</p>
    <p>Les répertoires sont indiqués par l'icône <span class="icon-rep"></span>, cliquer dessus affiche le répertoire correspondant. Les documents sont indiqués par l'icône correspondant à leur type (<span class="icon-doc-pdf"></span> pour les <code>pdf</code>, <span class="icon-doc-doc"></span> pour les textes <code>doc</code> ou <code>odt</code>...). Le contenu des répertoires et les principales propriétés des documents sont indiqués à droite.</p>
    <p>La taille des fichiers envoyés est limitée à <?php echo $taille; ?>.</p>
    <h4>Actions générales possibles</h4>
    <p>Les boutons généraux, en haut à droite de la page, s'appliquent sur le répertoire actuellement affiché. Elles permettent de&nbsp;:</p>
    <ul>
      <li><span class="icon-ajoutedoc"></span>&nbsp;: ajouter des documents à l'intérieur du répertoire.</li>
      <li><span class="icon-ajouterep"></span>&nbsp;: ajouter un sous-répertoire à l'intérieur du répertoire.</li>
      <li><span class="icon-viderep"></span>&nbsp;: supprimer une partie du contenu du répertoire (sous-répertoires et documents). Une sélection sera demandée.</li>
      <li><span class="icon-lock"></span>&nbsp;: modifier les réglages d'accès en lecture et téléchargement au répertoire (voir les détails ci-dessous).</li>
      <li><span class="icon-prefs"></span>&nbsp;: modifier le nom du répertoire, son apparition ou non dans le menu&nbsp; il est aussi possible de déplacer ce sous-répertoire à l'intérieur d'un autre répertoire au sein du répertoire «&nbsp;Général&nbsp;» ou des répertoires de vos matières.</li>
      <li><span class="icon-lecture"></span>&nbsp;: accéder à la modification du «&nbsp;mode de lecture&nbsp;», qui permet de voir le contenu de cette page comme la voit un autre type de compte, notamment pour vérifier les accès en lecture aux répertoires et aux documents.</li>
    </ul>
    <h4>Actions spécifiques sur un sous-répertoire</h4>
    <p>Pour chaque sous-répertoire, les boutons sur la ligne correspondante permettent de&nbsp;:</p>
    <ul>
      <li><span class="icon-supprime"></span>&nbsp;: supprimer le sous-répertoire et tout son contenu. Une confirmation sera demandée.</li>
      <li><span class="icon-montre"></span>&nbsp;: rendre visible un sous-répertoire invisible. Son accès en lecture est automatiquement défini comme celui du répertoire parent (actuellement affiché dans la page). Tout son contenu (sous-répertoires et documents) est aussi rendu visible, et l'accès en lecture défini de la même façon. Les sous-répertoires déjà visibles et avec un autre réglage d'accès subissent une modification de ce réglage.</li>
      <li><span class="icon-cache"></span>&nbsp;: rendre invisible un sous-répertoire visible ainsi tout son contenu. L'ensemble ne sera visible que par les professeurs (éventuellement associés à la matière à laquelle est lié le répertoire).</li>
      <li><span class="icon-lock"></span>&nbsp;: modifier les réglages d'accès en lecture et téléchargement au sous-répertoire (voir les détails ci-dessous).</li>
      <li><span class="icon-ajoutedoc"></span>&nbsp;: ajouter des documents à l'intérieur du sous-répertoire.</li>
      <li><span class="icon-prefs"></span>&nbsp;: modifier le nom du sous-répertoire, son apparition ou non dans le menu&nbsp; il est aussi possible de déplacer ce sous-répertoire à l'intérieur d'un autre répertoire au sein du répertoire «&nbsp;Général&nbsp;» ou des répertoires de vos matières.</li>
    </ul>
    <h4>Actions spécifiques sur un document</h4>
    <p>Pour chaque document, les boutons sur la ligne correspondante permettent de&nbsp;:</p>
    <ul>
      <li><span class="icon-supprime"></span>&nbsp;: supprimer le document. Une confirmation sera demandée.</li>
      <li><span class="icon-montre"></span>&nbsp;: rendre visible un document invisible. Son accès en lecture est automatiquement défini comme celui du répertoire parent (actuellement affiché dans la page).</li>
      <li><span class="icon-cache"></span>&nbsp;: rendre invisible un document visible. Il ne sera visible que par les professeurs (éventuellement associés à la matière à laquelle est lié le répertoire).</li>
      <li><span class="icon-lock"></span>&nbsp;: modifier les réglages d'accès en lecture au document (voir les détails ci-dessous).</li>
      <li><span class="icon-recent"></span>&nbsp;: définir une date d'affichage différé du document.</li>
      <li><span class="icon-actualise"></span>&nbsp;: envoyer une nouvelle version du document, qui remplacera l'existante.</li>
      <li><span class="icon-prefs"></span>&nbsp;: modifier le nom du document ou le déplacer à l'intérieur d'un autre répertoire au sein du répertoire «&nbsp;Général&nbsp;» ou des répertoires de vos matières.</li>
    </ul>
    <h4>Actions d'affichage et de téléchargement dans la barre de chemin</h4>
    <p>Au-dessus du contenu du répertoire se trouve la barre de chemin, contenant le chemin du répertoire et cinq boutons.</p>
    <ul>
      <li><span class="icon-alphaasc"></span> et <span class="icon-alphadesc"></span>&nbsp;: afficher selon l'ordre alphabétique, normal ou inversé</li>
      <li><span class="icon-chronoasc"></span> et <span class="icon-chronodesc"></span>&nbsp;: afficher selon l'ordre chronologique d'envoi, normal ou inversé</li>
      <li><span class="icon-downloadrep"></span>&nbsp;: télécharger tout ou partie du répertoire. Une fenêtre de sélection apparaît alors et permet de choisir les sous-répertoires directs et les documents présents dans le répertoire que l'on souhaite télécharger. Le téléchargement dans les sous-répertoires est récursif.</li>
    </ul>
    <p>Le bouton de téléchargement est potentiellement visible sur les sessions d'autres utilisateurs. Il est systématiquement présent pour les professeurs sur leurs matières associées, mais ne s'affiche pour les autres utilisateurs que si le réglage en téléchargement l'autorise (voir ci-dessous).</p>
    <p>Techniquement, le téléchargement est entièrement réalisé par le navigateur de l'utilisateur, qui fabrique localement un fichier <code>zip</code> contenant tous les documents et conservant l'arborescence visible sur le Cahier. Les répertoires vides n'y apparaissent pas.</p>
    <h4>Mise à jour d'un document</h4>
    <p>Les liens vers les répertoires et les documents sont garantis&nbsp;: aucune modification (changement de nom, mise à jour de document, déplacement...) réalisée sur les répertoires ou les documents ne peut modifier ces liens. Si vous souhaitez mettre à jour un document, surtout ne le supprimez pas pour le recréer&nbsp;: cela changerait le lien, les liens existants ne seraient plus valables. Il faut plutôt le mettre à jour à l'aide du bouton <span class="icon-actualise"></span>.</p>
    <h4>Réglages de l'accès en lecture</h4>
    <p>L'accès à chaque répertoire et à chaque document peut être protégé indépendamment. Il est modifiable en cliquant sur les boutons <span class="icon-lock"></span>, en haut à droite pour le répertoire affiché ici ou à droite sur chaque ligne correspondant à un sous-répertoire ou un document. Trois catégories de choix existent&nbsp;:</p>
    <ul>
      <li><em>Accès public</em>&nbsp;: répertoire ou document visible par tout visiteur, sans identification. Les moteurs de recherche ont accès aux documents. La page d'un répertoire en accès public n'a pas de cadenas <span class="icon-lock"></span> affiché à droite de son titre.</li>
      <li><em>Utilisateurs identifiés</em>&nbsp;: répertoire ou document visible uniquement par les utilisateurs identifiés à déterminer, en fonction de leur type de compte. Sur la page d'un répertoire, un cadenas <span class="icon-lock"></span> est alors affiché à droite de son titre. En cliquant dessus, un texte listant les comptes ayant accès en lecture à la page s'affiche.</li>
      <li><em>Répertoire/Document invisible</em>&nbsp;: ressource entièrement invisible pour les utilisateurs autres que les professeurs (éventuellement associés à la matière à laquelle la page est liée). Un cadenas <span class="icon-locktotal"></span> est alors affiché dans le titre de la page correspondant à un répertoire.</li>
    </ul>
    <h4>Réglages de l'accès en téléchargement</h4>
    <p>Indépendemment de l'accès en lecture, il est possible de régler pour chaque répertoire un accès en téléchargement, selon trois valeurs possibles&nbsp;:</p>
    <ul>
      <li>Pas de téléchargement possible&nbsp;: le bouton de téléchargement n'apparaît pas pour les utilisateurs qui ne sont pas professeurs associés à la matière.</li>
      <li>Téléchargement uniquement par les utilisateurs connectés&nbsp;: l'identification avec mot de passe est nécessaire avant de pouvoir avoir le bouton de téléchargement.</li>
      <li>Téléchargement possible par tout visiteur&nbsp;: le bouton de téléchargement est visible pour tout le monde.</li>
    </ul>
    <p>Cet accès en téléchargement ne supplante pas l'accès en lecture&nbsp;: si un document n'est pas autorisé pour un type d'utilisateur par le réglage en lecture, il ne sera pas téléchargé lorsque le répertoire est téléchargé, et il ne sera pas proposé au téléchargement dans la phase de sélection.</p>
    <p>Le réglage en téléchargement n'est possible que pour les répertoires, et est indépendant pour chaque répertoire. Les documents d'un répertoire héritent automatiquement de son réglage.</p>
    <p>Le téléchargement est une aide technique mais n'est pas obligatoire. Un professeur a tout à fait le droit de ne pas vouloir aider des élèves à récupérer des énoncés qui ont été donnés en version papier au long de l'année. Le risque de transmission aux élèves des années suivantes est réel, avec les problèmes pédagogiques que cela peut entraîner. Un message expliquant cela est affiché aux élèves lors de la phase de sélection des contenus à télécharger.</p>
    <p>Le bouton de téléchargement indique aussi le réglage en téléchargement de tous les sous-répertoires.</p>
    <h4>Affichage de l'accès aux sous-répertoires et documents</h4>
    <p>Les sous-répertoires et documents peuvent avoir le même réglage d'accès que le répertoire parent ou non.</p>
    <ul>
      <li>Une ressource visible par tous les utilisateurs ayant accès au répertoire parent n'a pas de cadenas à gauche de son titre. C'est le cas le plus classique et le réglage par défaut à la création d'un sous-répertoire ou à l'envoi d'un document.</li>
      <li>Une ressource visible par une partie des utilisateurs ayant accès au répertoire parent est marquée par un cadenas noir <span class="icon-lock"></span> à gauche de son titre. Ceci permet par exemple, dans un répertoire visible sans identification (sans taper son mot de passe), de positionner un document réservé aux élèves ou aux colleurs.</li>
      <li>Une ressource visible par des utilisateurs n'ayant pas accès au répertoire parent est marquée par un cadenas rouge <span class="icon-lock mev"></span> à gauche de son titre. Ce réglage, a priori rarement utile, peut permettre d'ajouter un document dans la page des <a href="recent"><span class="icon-recent"></span>&nbsp;derniers contenus</a> pour des utilisateurs qui ne verraient pas le répertoire parent.</li>
    </ul>
    <p>Un clic sur le cadenas à gauche du titre de chaque sous-répertoire ou document permet de voir le détail du réglage d'accès au répertoire parent et à la ressource en question.</p>
    <h4>Affichage différé pour les documents</h4>
    <p>Il est possible de régler un affichage différé pour un document&nbsp;: le document reste alors invisible jusqu'à la date-heure définie. Il apparaîtra à cette date avec le réglage d'accès choisi, simultanément sur cette page et sur la page des derniers contenus (lien <span class="icon-recent"></span> du menu).</p>
    <p>Les documents en affichage différé sont indiqués par une couleur grise dans la liste des documents, avec un bouton horloge rouge <span class="icon-recent mev"></span>. Un survol à la souris de ce bouton permet de voir à quelle heure apparaîtra le document.</p>
    <h4>Liens dans le menu</h4>
    <p>Le lien dans le menu vers le répertoire racine de chaque matière est généré automatiquement. Il ne s'affiche que si ce répertoire est non vide&nbsp;:</p>
    <ul>
      <li>pour les visiteurs non identifiés, si des documents éventuellement protégés sont présents (les répertoires et documents protégés apparaissent avec un cadenas &nbsp;<span class="icon-minilock">&nbsp;</span>).</li>
      <li>pour les utilisateurs identifiés, si des documents sont présents et si le répertoire racine est accessible.</li>
    </ul>
    <p>Il est possible de rajouter des liens dans le menu vers des sous-répertoires. C'est une des propriétés que vous pouvez modifier pour chaque répertoire en cliquant sur le bouton <span class="icon-prefs"></span> correspondant.</p>
    <h4>Lire aussi...</h4>
    <p>D'autres <span class="icon-aide"></span>&nbsp;aides sont aussi disponibles dans chaque formulaire d'action. N'hésitez pas à les consulter&nbsp;!</p>
  </div>

  <div id="aide-ajoutedoc">
    <h3>Aide et explications</h3>
    <p>Ce formulaire permet d'ajouter des documents dans le répertoire concerné. Il sera validé par un clic sur <span class="icon-ok"></span>, et abandonné (donc supprimé) par un clic sur <span class="icon-ferme"></span>.</p>
    <p>En cliquant sur le bouton de chargement des fichiers, une fenêtre gérée par votre navigateur va s'ouvrir. Vous pourrez y choisir plusieurs documents, par exemple en appuyant sur la touche <code>Ctrl</code> tout en cliquant sur les documents.</p>
    <p>La taille maximale de l'ensemble des <em>fichiers</em> envoyés est <?php echo $taille; ?>.</p>
    <p>Après validation des fichiers choisis, autant de cases de <em>noms à afficher</em> appraîtront. Il s'agit du nom, pour chaque document, affiché sur le site et au téléchargement. Ils valent par défaut le nom du fichier sur votre ordinateur, mais vous pouvez les modifier.</p>
    <p>Les <em>noms à afficher</em> des documents peuvent comporter des espaces, des accents... Vous pouvez donc les écrire en français&nbsp;!</p>
    <h4>Réglage de l'accès</h4>
    <p>L'accès aux documents peut être protégé. Trois catégories de choix sont possibles&nbsp;:</p>
    <ul>
      <li><em>Accès public</em>&nbsp;: documents téléchargeables par tout visiteur, sans identification. Les moteurs de recherche y auront accès.</li>
      <li><em>Utilisateurs identifiés</em>&nbsp;: documents téléchargeables uniquement par les utilisateurs identifiés à déterminer, en fonction de leur type de compte.</li>
      <li><em>Documents invisibles</em>&nbsp;: documents entièrement invisibles pour les utilisateurs autres que les professeurs (éventuellement associés à la matière à laquelle les documents sont liés). Un cadenas <span class="icon-locktotal"></span> sera alors affiché à côté de l'icône de chaque document.</li>
    </ul>
    <p>À moins d'être <em>invisible</em>, le nom d'un document est visible pour les visiteurs non identifiés. Les documents protégés apparaissent avec un cadenas &nbsp;<span class="icon-minilock">&nbsp;</span> et le téléchargement demande alors l'identification des visiteurs.</p>
    <p>Le réglage d'accès à tous les documents envoyés simultanément est identique, mais modifiable individuellement après envoi à l'aide des boutons <span class="icon-lock"></span> de chaque document.</p>
    <h4>Affichage différé</h4>
    <p>Il est possible de choisir un <em>affichage différé</em>&nbsp;: le document restera alors invisible jusqu'à la date-heure définie (nécessairement dans le futur), puis apparaîtra avec le réglage d'accès choisi, simultanément sur cette page et sur la page des derniers contenus (lien <span class="icon-recent"></span> du menu).</p>
    <p>Il est donc impossible de définir un affichage différé pour un document invisible. Il faut lui définir d'abord un autre réglage d'accès.</p>
    <p>Les documents en affichage différé sont indiqués par une couleur grise dans la liste des documents, avec un bouton horloge rouge <span class="icon-recent mev"></span>. Un survol à la souris de ce bouton permet de voir à quelle heure apparaîtra le document.</p>
  </div>

  <div id="aide-ajouterep">
    <h3>Aide et explications</h3>
    <p>Ce formulaire permet de créer un nouveau répertoire au sein du répertoire <em><?php echo $rep['nom']; ?></em>. Il sera validé par un clic sur <span class="icon-ok"></span>, et abandonné (donc supprimé) par un clic sur <span class="icon-ferme"></span>.</p>
    <p>Le <em>nom</em> du répertoire peut comporter des espaces, des accents... Vous pouvez donc l'écrire en français&nbsp;!</p>
    <p>La case à cocher <em>Affichage du répertoire dans le menu</em> permet d'afficher un lien direct dans le menu vers la page correspondant au répertoire. Ce lien sera situé en-dessous du lien <em>Documents à télécharger</em> pour la matière concernée. Il ne sera visible que pour les utilisateurs qui ont accès à ce répertoire.</p>
    <h4>Réglages de l'accès en lecture</h4>
    <p>L'accès à chaque répertoire et à chaque document peut être protégé indépendamment. Trois catégories de choix sont possibles&nbsp;:</p>
    <ul>
      <li><em>Accès public</em>&nbsp;: répertoire ou document visible par tout visiteur, sans identification. Les moteurs de recherche ont accès aux documents. La page d'un répertoire en accès public n'a pas de cadenas <span class="icon-lock"></span> affiché à droite de son titre.</li>
      <li><em>Utilisateurs identifiés</em>&nbsp;: répertoire ou document visible uniquement par les utilisateurs identifiés à déterminer, en fonction de leur type de compte. Sur la page d'un répertoire, un cadenas <span class="icon-lock"></span> est alors affiché à droite de son titre. En cliquant dessus, un texte listant les comptes ayant accès en lecture à la page s'affiche.</li>
      <li><em>Répertoire invisible</em>&nbsp;: ressource entièrement invisible pour les utilisateurs autres que les professeurs (éventuellement associés à la matière à laquelle la page est liée). Un cadenas <span class="icon-locktotal"></span> est alors affiché dans le titre de la page correspondant à un répertoire.</li>
    </ul>
    <p>À moins d'être <em>invisible</em>, le nom d'un répertoire est visible pour les visiteurs non identifiés. Les répertoires protégés apparaissent avec un cadenas &nbsp;<span class="icon-minilock">&nbsp;</span> et y accéder nécessite alors l'identification des visiteurs.</p>
    <h4>Réglages de l'accès en téléchargement</h4>
    <p>Indépendemment de l'accès en lecture, il est possible de régler pour chaque répertoire un accès en téléchargement, selon trois valeurs possibles&nbsp;:</p>
    <ul>
      <li>Téléchargement possible <em>par personne sauf vous</em>&nbsp;: le bouton de téléchargement n'apparaît pas pour les utilisateurs qui ne sont pas professeurs associés à la matière.</li>
      <li>Téléchargement possible <em>par les utilisateurs connectés</em>&nbsp;: l'identification avec mot de passe est nécessaire avant de pouvoir avoir le bouton de téléchargement.</li>
      <li>Téléchargement possible <em>par tout visiteur du site</em>&nbsp;: le bouton de téléchargement est visible pour tout le monde.</li>
    </ul>
    <p>Pour les utilisateurs autorisés, le téléchargement se matérialise par un bouton dans la barre de chemin. Un clic fait apparaître une fenêtre de sélection qui permet de choisir les sous-répertoires directs et les documents présents dans le répertoire que l'on souhaite télécharger. Le téléchargement dans les sous-répertoires est récursif.</p>
    <p>Cet accès en téléchargement ne supplante pas l'accès en lecture&nbsp;: si un document n'est pas autorisé pour un type d'utilisateur par le réglage en lecture, il ne sera pas téléchargé lorsque le répertoire est téléchargé, et il ne sera pas proposé au téléchargement dans la phase de sélection.</p>
    <p>Le réglage en téléchargement n'est possible que pour les répertoires, et est indépendant pour chaque répertoire. Les documents d'un répertoire héritent automatiquement de son réglage.</p>
    <p>Le téléchargement est une aide technique mais n'est pas obligatoire. Un professeur a tout à fait le droit de ne pas vouloir aider des élèves à récupérer des énoncés qui ont été donnés en version papier au long de l'année. Le risque de transmission aux élèves des années suivantes est réel, avec les problèmes pédagogiques que cela peut entraîner. Un message expliquant cela est affiché aux élèves lors de la phase de sélection des contenus à télécharger.</p>
  </div>

  <div id="aide-viderep">
    <h3>Aide et explications</h3>
    <p>Ce formulaire permet de supprimer en une seule fois une partie du contenu du répertoire <em><?php echo $rep['nom']; ?></em>. Il sera validé par un clic sur <span class="icon-ok"></span>, et abandonné (donc supprimé) par un clic sur <span class="icon-ferme"></span>.</p>
    <p>Tous les sous-répertoires et documents présents dans ce répertoire sont listés dans le tableau, identifiables par leur <em>nom</em>. Les sous-répertoires et documents cachés ont un <em>nom</em> en italique.</p>
    <p>Chaque sous-répertoire ou document coché (avec une case cochée sur la ligne) sera supprimé après validation du formulaire.</p>
    <p>La suppression d'un sous-répertoire signifie que tous les répertoires et documents qu'il contient sont automatiquement supprimés également.</p>
    <p>Il est possible de facilement sélectionner les sous-répertoires et documents cachés uniquement en cochant la case <em>Cocher toutes les ressources cachées</em> située au-dessus du tableau.</p>
    <p>Il est possible de facilement sélectionner tous les sous-répertoires et documents en cochant la case <span class="icon-cocher"></span> située en haut du tableau.</p>
    <p>Comme toutes les actions de suppression, cette action est définitive.</p>
    <p>La suppression est immédiate dès le clic sur <span class="icon-ok"></span>. Aucune confirmation ne sera demandée.</p>
  </div>

  <div id="aide-downloadrep">
    <p>Ce formulaire permet de télécharger tout ou partie du contenu du répertoire <em><?php echo $rep['nom']; ?></em>. Il sera validé par un clic sur <span class="icon-download"></span>, et abandonné (donc supprimé) par un clic sur <span class="icon-ferme"></span>.</p>
    <p>Tous les sous-répertoires et documents présents dans ce répertoire sont listés dans le tableau, identifiables par leur <em>nom</em>. Les sous-répertoires et documents cachés ont un <em>nom</em> en italique.</p>
    <p>Chaque sous-répertoire ou document coché (avec une case cochée sur la ligne) sera ajouté à un téléchargement global après validation du formulaire. Techniquement, le téléchargement est entièrement réalisé par le navigateur de l'utilisateur, qui fabrique localement un fichier <code>zip</code> contenant tous les documents et conservant l'arborescence visible sur le Cahier. Les répertoires vides n'y apparaissent pas.</p>
    <p>Il est possible de facilement désélectionner les sous-répertoires et documents cachés en cochant la case <em>Ne pas inclure les documents cachés</em> située au-dessus du tableau. Ce comportement est récursif&nbsp;: tous les documents cachés contenus dans les sous-répertoires sont alors ignorés.</p>
    <p>Il est possible de facilement sélectionner tous les sous-répertoires et documents en cochant la case <span class="icon-cocher"></span> située en haut du tableau.</p>
    <p>L'interface de téléchargement pour les autres utilisateurs ne mentionne pas les documents cachés.</p>
  </div>

  <div id="aide-prefsrep">
    <h3>Aide et explications</h3>
    <p>Ce formulaire permet de modifier les propriétés du répertoire concerné. Il sera validé par un clic sur <span class="icon-ok"></span>, et abandonné (donc supprimé) par un clic sur <span class="icon-ferme"></span>.</p>
    <p>Le <em>nom</em> du répertoire peut comporter des espaces, des accents... Vous pouvez donc l'écrire en français&nbsp;! Ce nom est aussi modifiable directement en cliquant sur le bouton <span class="icon-edite"></span> dans la case entourée de pointillés.</p>
    <p>La case à cocher <em>Affichage du répertoire dans le menu</em> permet d'afficher un lien direct dans le menu vers la page correspondant au répertoire. Ce lien sera situé en-dessous du lien <em>Documents à télécharger</em> pour la matière concernée. Il ne sera visible que pour les utilisateurs qui ont accès à ce répertoire.</p>
    <p>Vous pouvez <em>déplacer</em> le répertoire dans un autre répertoire, qui peut éventuellement appartenir à une autre matière si elle vous est associée. Le menu déroulant contient la liste des répertoires où le déplacement est possible. L'ensemble du contenu du répertoire déplacé est bien sûr automatiquement déplacé. L'accès n'est pas modifié.</p>
  </div>

  <div id="aide-lockrep">
    <h3>Aide et explications</h3>
    <p>Ce formulaire permet de modifier l'accès en lecture et téléchargement au répertoire correspondant. Il sera validé par un clic sur <span class="icon-ok"></span>, et abandonné (donc supprimé) par un clic sur <span class="icon-ferme"></span>.</p>
    <h4>Réglages de l'accès en lecture</h4>
    <p>L'accès à chaque répertoire et à chaque document peut être protégé indépendamment. Trois catégories de choix sont possibles&nbsp;:</p>
    <ul>
      <li><em>Accès public</em>&nbsp;: répertoire ou document visible par tout visiteur, sans identification. Les moteurs de recherche ont accès aux documents. La page d'un répertoire en accès public n'a pas de cadenas <span class="icon-lock"></span> affiché à droite de son titre.</li>
      <li><em>Utilisateurs identifiés</em>&nbsp;: répertoire ou document visible uniquement par les utilisateurs identifiés à déterminer, en fonction de leur type de compte. Sur la page d'un répertoire, un cadenas <span class="icon-lock"></span> est alors affiché à droite de son titre. En cliquant dessus, un texte listant les comptes ayant accès en lecture à la page s'affiche.</li>
      <li><em>Répertoire invisible</em>&nbsp;: ressource entièrement invisible pour les utilisateurs autres que les professeurs (éventuellement associés à la matière à laquelle la page est liée). Un cadenas <span class="icon-locktotal"></span> est alors affiché dans le titre de la page correspondant à un répertoire.</li>
    </ul>
    <h4>Réglages de l'accès en téléchargement</h4>
    <p>Indépendemment de l'accès en lecture, il est possible de régler pour chaque répertoire un accès en téléchargement, selon trois valeurs possibles&nbsp;:</p>
    <ul>
      <li>Téléchargement possible <em>par personne sauf vous</em>&nbsp;: le bouton de téléchargement n'apparaît pas pour les utilisateurs qui ne sont pas professeurs associés à la matière.</li>
      <li>Téléchargement possible <em>par les utilisateurs connectés</em>&nbsp;: l'identification avec mot de passe est nécessaire avant de pouvoir avoir le bouton de téléchargement.</li>
      <li>Téléchargement possible <em>par tout visiteur du site</em>&nbsp;: le bouton de téléchargement est visible pour tout le monde.</li>
    </ul>
    <p>Pour les utilisateurs autorisés, le téléchargement se matérialise par un bouton dans la barre de chemin. Un clic fait apparaître une fenêtre de sélection qui permet de choisir les sous-répertoires directs et les documents présents dans le répertoire que l'on souhaite télécharger. Le téléchargement dans les sous-répertoires est récursif.</p>
    <p>Cet accès en téléchargement ne supplante pas l'accès en lecture&nbsp;: si un document n'est pas autorisé pour un type d'utilisateur par le réglage en lecture, il ne sera pas téléchargé lorsque le répertoire est téléchargé, et il ne sera pas proposé au téléchargement dans la phase de sélection.</p>
    <p>Le réglage en téléchargement n'est possible que pour les répertoires, et est indépendant pour chaque répertoire. Les documents d'un répertoire héritent automatiquement de son réglage.</p>
    <p>Le téléchargement est une aide technique mais n'est pas obligatoire. Un professeur a tout à fait le droit de ne pas vouloir aider des élèves à récupérer des énoncés qui ont été donnés en version papier au long de l'année. Le risque de transmission aux élèves des années suivantes est réel, avec les problèmes pédagogiques que cela peut entraîner. Un message expliquant cela est affiché aux élèves lors de la phase de sélection des contenus à télécharger.</p>
    <p>Le bouton de téléchargement indique aussi le réglage en téléchargement de tous les sous-répertoires.</p>
    <h4>Propagation du réglage</h4>
    <p>La case à cocher <em>Propager ces choix d'accès à chaque document/sous-répertoire</em> permet de copier les réglages choisis (modifiés ou non) pour ce répertoire à l'ensemble de son contenu, sous-répertoires et documents.</p>
    <p>À moins d'être <em>invisible</em>, le nom d'un répertoire est visible pour les visiteurs non identifiés. Les répertoires protégés apparaissent avec un cadenas &nbsp;<span class="icon-minilock">&nbsp;</span> et y accéder nécessite alors l'identification des visiteurs.</p>
  </div>

  <div id="aide-lockdoc">
    <h3>Aide et explications</h3>
    <p>Ce formulaire permet de modifier l'accès au document correspondant. Il sera validé par un clic sur <span class="icon-ok"></span>, et abandonné (donc supprimé) par un clic sur <span class="icon-ferme"></span>.</p>
    <p>Trois catégories de choix sont possibles&nbsp;:</p>
    <ul>
      <li><em>Accès public</em>&nbsp;: document téléchargeable par tout visiteur, sans identification. Les moteurs de recherche y auront accès.</li>
      <li><em>Utilisateurs identifiés</em>&nbsp;: document téléchargeable uniquement par les utilisateurs identifiés à déterminer, en fonction de leur type de compte.</li>
      <li><em>Document invisible</em>&nbsp;: document entièrement invisible pour les utilisateurs autres que les professeurs (éventuellement associés à la matière à laquelle les documents sont liés). Un cadenas <span class="icon-locktotal"></span> sera alors affiché à côté de l'icône du document.</li>
    </ul>
    <p>À moins d'être <em>invisible</em>, le nom d'un document est visible pour les visiteurs non identifiés. Les documents protégés apparaissent avec un cadenas &nbsp;<span class="icon-minilock">&nbsp;</span> et le téléchargement demande alors l'identification des visiteurs.</p>
  </div>

  <div id="aide-recent">
    <h3>Aide et explications</h3>
    <p>Ce formulaire permet de modifier le réglage d'accès et la date d'affichage différé du document correspondant. Il sera validé par un clic sur <span class="icon-ok"></span>, et abandonné (donc supprimé) par un clic sur <span class="icon-ferme"></span>.</p>
    <h4>Réglage de l'accès</h4>
    <p>Trois catégories de choix sont possibles&nbsp;:</p>
    <ul>
      <li><em>Accès public</em>&nbsp;: document téléchargeable par tout visiteur, sans identification. Les moteurs de recherche y auront accès.</li>
      <li><em>Utilisateurs identifiés</em>&nbsp;: document téléchargeable uniquement par les utilisateurs identifiés à déterminer, en fonction de leur type de compte.</li>
      <li><em>Document invisible</em>&nbsp;: document entièrement invisible pour les utilisateurs autres que les professeurs (éventuellement associés à la matière à laquelle les documents sont liés). Un cadenas <span class="icon-locktotal"></span> sera alors affiché à côté de l'icône du document.</li>
    </ul>
    <p>Ce réglage est identique à celui que l'on peut ajuster en utilisant le bouton <span class="icon-lock"></span>.</p>
    <h4>Affichage différé</h4>
    <p>Si une date de <em>disponibilité</em> est choisie (nécessairement dans le futur), le document restera alors invisible jusqu'à la date-heure définie, puis apparaîtra avec le réglage d'accès choisi, simultanément sur la page et sur la page des derniers contenus (lien <span class="icon-recent"></span> du menu).</p>
    <p>Il est donc impossible de définir un affichage différé pour un document invisible. Il faut lui définir d'abord un autre réglage d'accès.</p>
    <p>Valider une <em>disponibilité</em> vide annule l'affichage différé&nbsp;: le document est publié immédiatement avec le réglage d'accès choisi.</p>
    <p>Les documents en affichage différé sont indiqués par une couleur grise dans la liste des documents, avec un bouton horloge rouge <span class="icon-recent mev"></span>. Un survol à la souris de ce bouton permet de voir à quelle heure apparaîtra le document.</p>
  </div>

  <div id="aide-actualise">
    <h3>Aide et explications</h3>
    <p>Ce formulaire permet de <em>mettre à jour</em> le document correspondant. Il sera validé par un clic sur <span class="icon-ok"></span>, et abandonné (donc supprimé) par un clic sur <span class="icon-ferme"></span>.</p>
    <p>En cliquant sur le bouton de chargement du nouveau fichier, une fenêtre gérée par votre navigateur va s'ouvrir. Vous pourrez y choisir le fichier à envoyer.</p>
    <p>La nouvelle version doit être nécessairement un fichier du même type (même extension).</p>
    <p>La taille maximale du fichier envoyé est <?php echo $taille; ?>.</p>
    <p><em>Mettre à jour</em> le document est bien mieux que de supprimer/recréer le document, car les liens existants restent valables. Une fois le formulaire validé, la taille du document est automatiquement modifiée.</p>
    <p>La case à cocher <em>Publier en tant que mise à jour</em> permet de mentionner sur la page des <a href="recent"><span class="icon-recent"></span>&nbsp;derniers contenus</a> que le document a été mis à jour. Cela modifie la date marquée pour l'envoi du document et le déplace donc en haut de cette page. C'est une bonne chose à faire si la correction du document est importante et qu'il est utile que les élèves voient qu'il faut le télécharger à nouveau, comme dans le cas d'une nouvelle version de colloscope. C'est moins utile pour une simple faute de frappe...</p>
  </div>

  <div id="aide-prefsdoc">
    <h3>Aide et explications</h3>
    <p>Ce formulaire permet de modifier le nom du document correspondant ou de le déplacer. Il sera validé par un clic sur <span class="icon-ok"></span>, et abandonné (donc supprimé) par un clic sur <span class="icon-ferme"></span>.</p>
    <p>Le <em>nom</em> du document peut comporter des espaces, des accents... Vous pouvez donc l'écrire en français&nbsp;! Ce nom est aussi modifiable directement en cliquant sur le bouton <span class="icon-edite"></span> dans la case entourée de pointillés. Il ne doit pas comporter l'extension, qui est ajoutée lorsqu'on le télécharge.</p>
    <p>Vous pouvez ici <em>déplacer</em> le document dans un autre répertoire, qui peut éventuellement appartenir à une autre matière si elle vous est associée. La liste des répertoires où le déplacement est possible est dans le menu déroulant. Les lien existant vers le document n'est pas modifié.</p>
  </div>

<?php
}

if ( $rid && ( $edition || $admin ) )  {
  // Textes affichés sur les éventuelles icônes du titre
  switch ( $rep['protection'] )  {
    case 0: break;
    case 32:
      echo "  <div id=\"aide-affprotection\"><strong>Le contenu de ce répertoire n'est visible que pour les professeurs$textematiere.</strong> Il n'est pas accessible aux autres utilisateurs.<br> Mais les sous-répertoires précédés d'un cadenas rouge <span class=\'icon-lock mev\'></span> peuvent rester accessibles à d'autres utilisateurs, à condition de connaître ou récupérer le lien correspondant.</div>\n\n";
      break;
    case 1:
      echo "  <div id=\"aide-affprotection\"><strong>Le contenu de ce répertoire est visible par tous les utilisateurs connectés ayant saisi leur mot de passe, invisible sans connexion.</strong><br> Les icônes cadenas <span class=\"icon-lock\"></span> à gauche de chaque nom de sous-répertoire ou document indiquent que la protection d'accès de la ressource est différente.<br> <span class=\"mev\">Le cadenas est rouge</span> si le sous-répertoire ou document est visible sans connexion (en ayant le lien pour les sous-répertoires ou directement sur la page des <span class=\"icon-recent\"></span>&nbsp;derniers contenus pour les documents).<br> Le cadenas est noir si la ressource n'est visible que par une partie des utilisateurs connectés.</div>\n\n";
      break;
    default:
      $p = $rep['protection']-1;
      $texte = preg_replace('/,([^,]+)$/',' et$1',implode(', les ',array_filter($comptes,function($a) use($p) { return !($p>>$a & 1); },ARRAY_FILTER_USE_KEY)));
      echo "  <div id=\"aide-affprotection\">En plus des professeurs$textematiere, <strong>le contenu de ce répertoire est visible par les $texte.</strong><br> Les icônes cadenas <span class=\"icon-lock\"></span> à gauche de chaque nom de sous-répertoire ou document indiquent que la protection d'accès de la ressource est différente.<br> <span class=\"mev\">Le cadenas est rouge</span> si le sous-répertoire ou document est visible par des utilisateurs n'ayant pas accès à cette page (en ayant le lien pour les sous-répertoires ou directement sur la page des <span class=\"icon-recent\"></span>&nbsp;derniers contenus pour les documents).<br> Le cadenas est noir si la ressource n'est visible que par une partie des utilisateurs ayant accès à cette page.</div>\n\n";
  }
}
?>

  <script type="text/javascript">
$( function() {
  $('a.ordre').on("click",function() {
    var h = window.location.href;
    var i = h.indexOf('ordre=');
    if ( $(this).hasClass('icon-alphaasc') )  {
      if ( i > 0 )
        window.location = ( h.indexOf('&',i) > 0 ) ? h.substr(0,i)+h.substr(h.indexOf('&',i)+1) : h.substr(0,i-1);
      return; // Rien à faire si pas d'ordre déjà réglé
    }
    if ( $(this).hasClass('icon-alphadesc') )   var o = 'alpha-inv';
    if ( $(this).hasClass('icon-chronoasc') )   var o = 'chrono';
    if ( $(this).hasClass('icon-chronodesc') )  var o = 'chrono-inv';
    if ( i > 0 )
      window.location = ( h.indexOf('&',i) > 0 ) ? h.substr(0,i+6)+o+h.substr(h.indexOf('&',i)) : h.substr(0,i+6)+o;
    else
      window.location = ( h.indexOf('?') > 0 ) ? h+'&ordre='+o : h+'?ordre='+o;
  });
});
  </script>
<?php
$mysqli->close();
fin($editionjs,false,'client-zip',$script ?? false);
?>
