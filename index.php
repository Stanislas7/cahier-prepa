<?php
// Sécurité
define('OK',1);
// Affichage des erreurs : à commenter en production
//ini_set('display_errors',1); error_reporting(E_ALL); ini_set('display_startup_errors',1);
// Configuration
include('config.php');
// Fonctions
include('fonctions.php');
  
////////////////////////////////////////////
// Description des droits de modification //
////////////////////////////////////////////
// * Prof associé à la matière = propriétaire
//   -> $edition = 2
//   -> a accès à tout, tout le temps
//   -> page : ajout d'info, suppression d'infos, modification de propriété de page 
//   -> infos : supprime, édite, montre, cache, monte, descend, accès, différé
//      (sans condition)
// * Administrateur
//   -> $edition indépendant
//   -> n'a accès à rien s'il n'y a pas droit par ailleurs
//   -> page : modification de propriété de page
//   -> infos : rien
// * Éditeur de page : selon "edition" dans la table "pages",
//   (prof = hors matière, autres utilisateurs = avec matière associée)
//   -> $edition = 1
//   -> page : ajout d'info, suppression d'infos (pas modification de propriété)
//   -> infos : supprime, édite, montre, cache, monte, descend, accès, différé
//      (selon "edition" dans la table "infos")
// * Éditeur d'une info : selon "edition" dans la table "infos"
//   -> infos : édite
// * Le mode lecture est possible pour tous les profs et admins, mais 
//   visible uniquement pour les éditeurs et admins.
////////////////////////////////////////////
// * La protection de la page inclut la possibilité d'édition de la page.
// * La protection de la page inclut la possibilité d'édition de chaque info.
// * La protection d'une info inclut la possibilité d'édition de l'info.
// * Une info peut être plus ou moins protégée en lecture que la page.
// * Une info peut être plus ou moins protégée en écriture que la page.
////////////////////////////////////////////

////////////////////////////////////////////
// Validation de la requête : clé de page //
////////////////////////////////////////////

// Recherche de la page concernée, variable $page
// Si $_REQUEST['cle'] existe, on le cherche dans les pages disponibles.
// Si $_REQUEST['cle'] n'est pas trouvée, page d'accueil par défaut.
$mysqli = connectsql();
$resultat = $mysqli->query('SELECT p.id, CONCAT_WS(\'/\',m.cle,p.cle) AS cle, p.matiere,
                                   p.nom, p.titre, p.bandeau, p.protection, p.edition
                            FROM pages AS p LEFT JOIN matieres AS m ON p.matiere = m.id
                            ORDER BY p.matiere, p.ordre');
if ( !empty($_REQUEST) )  {
  while ( $r = $resultat->fetch_assoc() )
    if ( isset($_REQUEST[$r['cle']]) )  {
      $page = $r;
      break;
    }
}
// Page par défaut : la première
if ( !isset($page) || ( $page['protection'] == 32 ) && ( ( $autorisation != 5 ) || !in_array($page['matiere'],explode(',',$_SESSION['matieres'])) ) )  {
  // Si pas de page : installation nécessaire
  if ( !$resultat )  {
    include('installation.php');
    exit();
  }
  $resultat->data_seek(0);
  $page = $resultat->fetch_assoc();
}
$resultat->free();

/////////////////////////////
// Vérification de l'accès //
/////////////////////////////
// $edition vaut 1 (true) pour un simple éditeur, 2 pour un professeur associé à la matière.
// $editionjs sert à charger edition.js, doit être vrai si administrateur, si édition de
// la page ou si edition=0 mais une info éditable
// La fonction acces() coupe l'exécution si elle n'est pas autorisée.
// MathJax désactivé par défaut
$admin = $_SESSION['admin'] ?? false;
$edition = acces($page['protection'],$page['matiere'],$page['titre'],(($page['id'] == 1)?'.':".?${page['cle']}"),$mysqli,$page['edition']);
$editionjs = $donnees = $mathjax = false;
if ( $edition || $admin )  {
  $editionjs = true;
  $donnees = array('action'=>'infos','matiere'=>$page['matiere'],'protection'=>$page['protection'],'edition'=>$page['edition'],'css'=>'datetimepicker');
}
$mode_lecture = ( $edition || $admin ) ? $_SESSION['mode_lecture'] : 0;

//////////////
//// HTML ////
//////////////
debut($mysqli,$page['titre'],$message,$autorisation,(($page['id'] == 1)?'.':".?${page['cle']}"),$donnees);

// Notification pour le blog
if ( $autorisation && $interfaceglobale && !$mode_lecture && ( $_SESSION['lastconn'] < file_get_contents("${interfaceglobale}sauvegarde/message".($admin ? 6 : $autorisation).'.txt') ) && !isset($_SESSION['blogcdpok']) )
  echo "\n  <div class=\"warning\">Il y a un nouveau message de l'administrateur sur le <a href=\"blogcdp\">blog</a></div>\n\n";

// Agenda éventuel
if ( $page['id'] == 1 )  {
  $resultat = $mysqli->query('SELECT GROUP_CONCAT(val ORDER BY nom) FROM prefs WHERE nom = "agenda_nbmax" OR nom = "agenda_protection"');
  list($nbmax,$protection) = explode(',',$resultat->fetch_row()[0]);
  $resultat->free();
  if ( ( $protection == 0 ) || ( ( $autorisation == 5 ) && ( $protection < 32 ) ) || $autorisation && !( ($protection-1)>>($autorisation-1) & 1 ) )  {
    $resultat = $mysqli->query('SELECT m.nom AS matiere, texte, t.nom AS type, t.id AS tid, t.index_nbmax,
                              DATE_FORMAT(debut,\'%w%Y%m%e\') AS d, DATE_FORMAT(fin,\'%w%Y%m%e\') AS f,
                              DATE_FORMAT(debut,\'%kh%i\') AS hd, DATE_FORMAT(fin,\'%kh%i\') AS hf
                              FROM agenda AS a LEFT JOIN `agenda-types` AS t ON a.type = t.id LEFT JOIN matieres AS m ON a.matiere = m.id
                              WHERE index_aff AND CURDATE() <= DATE(fin) AND ADDDATE(CURDATE(),index_datemax) >= DATE(debut) ORDER BY debut,fin LIMIT '.$nbmax);
    if ( $resultat->num_rows )  {
      echo "\n  <h2><a href=\"agenda\" title=\"Afficher l'agenda\" style=\"text-decoration: none;\"><span class=\"icon-agenda\"></span></a>&nbsp;À l'agenda en ce moment</h2>\n\n  <article>";
      $nbpartype = array();
      while ( $r = $resultat->fetch_assoc() )  {
        if ( ( $nbpartype[$r['tid']] = ( $nbpartype[$r['tid']] ?? 0 ) + 1 ) > $r['index_nbmax'] )
          continue;
        // Événement sur un seul jour
        if ( $r['d'] == $r['f'] )  {
          $date = substr(ucfirst(format_date($r['d'])),0,-5);
          if ( $r['hd'] != '0h00' )
            $date .= ( $r['hd'] == $r['hf'] ) ? ' à '.str_replace('00','',$r['hd']) : ' de '.str_replace('00','',$r['hd']).' à '.str_replace('00','',$r['hf']);
        }
        // Événement sur plusieurs jours
        else
          $date = ( $r['hd'] == '0h00' ) ? 'Du '.substr(format_date($r['d']),0,-5).' au '.substr(format_date($r['f']),0,-5)
                                         : 'Du '.substr(format_date($r['d']),0,-5).' à '.str_replace('00','',$r['hd']).' au '.substr(format_date($r['f']),0,-5).' à '.str_replace('00','',$r['hf']);
        $titre = ( $r['matiere'] ) ? "${r['type']} en ${r['matiere']}" : $r['type'];
        echo "\n  <h4>$date&nbsp;: $titre</h4>\n  ${r['texte']}\n";
      }
      $resultat->free();
      echo "  </article>\n\n";
    }
  }
}

// Icônes en haut de page
if ( $edition || $admin )  {
  if ( $mode_lecture )
    $icones = "    <a class=\"icon-lecture mev\" title=\"Modifier le mode de lecture\"></a>\n";
  else  {
    $icones = '';
    if ( $edition )
      $icones .= "    <a class=\"icon-ajoute formulaire\" title=\"Ajouter une nouvelle information\"></a>\n    <a class=\"icon-supprimeinfos formulaire\" title=\"Supprimer plusieurs informations\"></a>\n";
    if ( ( $edition == 2 ) || $admin )
      $icones .= "    <a class=\"icon-prefs formulaire\" title=\"Modifier les préférences de cette page\"></a>\n";
    if ( ( $autorisation == 5 ) || $admin )
      $icones .= "    <a class=\"icon-lecture\" title=\"Modifier le mode de lecture\"></a>\n";
    $icones .= "    <a class=\"icon-aide\" title=\"Aide pour les modifications de cette page\"></a>\n";
    // Script de choix datetime à charger en bas de page (méthode fin() en bas)
    $script = 'datetimepicker';
  }
  echo "\n\n  <div id=\"icones\" data-id=\"${page['id']}\" data-action=\"page\">\n$icones  </div>\n\n";
}

// Requête à réaliser
// Cas du mode lecture enclenché : affichage simplifié, uniquement les informations
// non cachées et autorisées pour le mode, sans édition possible mais avec
// indication si le type de compte peut éditer
if ( $mode_lecture )  {
  $a = $mode_lecture - 1;
  // Si la protection de la page n'empêche pas l'affichage
  if ( !$page['protection'] || $a && !( ( $page['protection']-1 ) >> ( $a-1 ) & 1 ) )  {
    // Si mode lecture pour des comptes éditeurs, on doit tout afficher
    if ( $e = intval( $page['edition'] && ( ($page['edition']-1)>>($a-1) & 1 ) ) )
      echo '  <div class="annonce">Le type de compte avec lequel vous regardez cette page est éditeur de la page : toutes les informations sont visibles, mais pas nécessairement modifiables.</div>';
    $requete = 'SELECT titre, texte, edition, 0 AS editable, '.requete_edition($a)." AS editable_ml 
                FROM infos WHERE page = ${page['id']} ".( $e ? '' : 'AND cache = 0 AND '.requete_protection($a).' AND dispo < NOW()' ).' ORDER BY ordre';
  }
  else
    $requete = 'SELECT 1 WHERE 0';
}
// Cas de l'édition possible de la page : affichage de toutes les informations
elseif ( $edition )  {
  $requete = 'SELECT id, ordre, cache, titre, texte, protection, edition, '. ( ( $edition == 2 ) ? '1' : requete_edition($autorisation) )." AS editable, 0 AS editable_ml,
              IF(dispo>NOW(),1,0) AS affdiff, DATE_FORMAT(dispo,'%d/%m/%Y %kh%i') AS dispo, DATE_FORMAT(dispo,'%w%Y%m%e') AS dispo2
              FROM infos WHERE page = ${page['id']} ORDER BY ordre";
  // Récupération du nombre d'informations sur la page
  $resultat = $mysqli->query("SELECT COUNT(*) FROM infos WHERE page = ${page['id']}");
  $max = $resultat->fetch_row()[0];
  $resultat->free();
}
// Cas de non édition de la page : affichage des informations non éditables
// mais aussi récupération pour affichage avec édition des champs pour les autres
else
  $requete = 'SELECT id, titre, texte, 0 as edition, '.requete_edition($autorisation)." AS editable, 0 AS editable_ml
              FROM infos WHERE page = ${page['id']} AND cache = 0 AND ".requete_protection($autorisation).' AND dispo < NOW() ORDER BY ordre';

// Valeurs utilisables dans plusieurs contextes
$textematiere = ( $page['matiere'] ) ? ' associés à la matière' : '';
$comptes = ( $page['matiere'] ) ? array('invités','élèves','colleurs','comptes de type lycée','professeurs (même non associés à la matière)') : array('invités','élèves','colleurs','comptes de type lycée');

// Récupération et affichage des informations
$resultat = $mysqli->query($requete);
if ( $resultat->num_rows )  {
  if ( $page['bandeau'] )
    echo "\n  <h2>${page['bandeau']}</h2>\n";
  while ( $r = $resultat->fetch_assoc() )  {
    $mathjax = $mathjax ?: boolval(strpos($r['texte'],'$')+strpos($r['texte'],'\\'));
    // Information éditable
    if ( $r['editable'] )  {
      // Affichage avec possibilité d'édition globale : édition du texte et suppression/déplacement/protection
      if ( $edition )  {
        $iconeprotection = $iconeedition = '';
        // Icônes de modification
        if ( $r['cache'] )  {
          $classe = ' class="cache"';
          $visible = '<a class="icon-montre" title="Afficher l\'information aux utilisateurs autorisés"></a>
      <a class="icon-cache" style="display:none;" title="Rendre invisible l\'information"></a>';
        }
        else  {
          $classe = '';
          $visible = '<a class="icon-montre" style="display:none;" title="Afficher l\'information aux utilisateurs autorisés"></a>
      <a class="icon-cache" title="Rendre invisible l\'information"></a>';
          // Affichage de la protection si différente de celle de la page
          if ( ( $p = $r['protection'] ) != $page['protection'] )  {
            if ( $p == 32 )
              $r['cache'] = true;
            else  {
              if ( $p )  {
                $texte = ( $p == 1 ) ? "tous les utilisateurs connectés"
                                       : 'les '.preg_replace('/,([^,]+)$/',' et$1',implode(', les ',array_filter($comptes,function($a) use($p) { return (32-$p)>>$a & 1; },ARRAY_FILTER_USE_KEY)));
                $iconeprotection = ( !$page['protection'] || ( $page['protection']-1 == ( ($p-1) & ($page['protection']-1) ) ) )
                  ? "<span class=\"icon-lock affichable\" data-title=\"<strong>La protection de cette information est plus restrictive que celle de la page</strong> : en plus des professeurs$textematiere, cette information est visible uniquement par $texte.\"></span>" 
                  : "<span class=\"icon-lock mev affichable\" data-title=\"<strong>La protection de cette information est moins restrictive que celle de la page</strong> : en plus des professeurs$textematiere, cette information est visible par $texte, y compris sur la page des <span class='icon-recent'></span>&nbsp;derniers contenus. Des utilisateurs n'ayant pas accès à la page peuvent donc voir cette information.\"></span>";
              }
              else
                $iconeprotection = '<span class="icon-lock mev affichable" data-title="Contrairement à la page, cette information est visible de tous. Des utilisateurs n\'ayant pas accès à la page peuvent voir cette information sur la page des <span class=\'icon-recent\'></span>&nbsp;derniers contenus."></span>';
            }
          }
          // Affichage de l'accès en édition si différent de celui de la page
          // Remarque : édition ne peut pas valoir 1 ou 2.
          if ( ( $e = $r['edition'] ) != $page['edition'] )  {
            if ( $e && ( $page['matiere'] || ( $e != 17 ) ) )  {
              $texte = preg_replace('/,([^,]+)$/',' et$1',implode(', les ',array_filter($comptes,function($a) use($e) { return ($e-1)>>$a & 1; },ARRAY_FILTER_USE_KEY)));
              $iconeedition = ( $page['edition'] && ( $page['edition']-1 == ( ($e-1) | ($page['edition']-1) ) ) )
                ? "<span class=\"icon-edite affichable\" data-title=\"<strong>La possibilité d'édition de cette information est restreinte par rapport à celle de la page</strong> : en plus des professeurs$textematiere, cette information est éditable uniquement par les $texte.\"></span>" 
                : "<span class=\"icon-edite mev affichable\" data-title=\"<strong>La possibilité d'édition de cette information est étendue par rapport à celle de la page</strong> : en plus des professeurs$textematiere, cette information est aussi éditable par les $texte.\"></span>";
            }
            else
              $iconeedition = "<span class=\"icon-edite affichable\" data-title=\"Cette information n'est éditable que par les professeurs$textematiere.\"></span>";
          }
        }
        $monte = ( $r['ordre'] == 1 ) ? ' style="display:none;"' : '';
        $descend = ( $r['ordre'] == $max ) ? ' style="display:none;"' : '';
        if ( $r['affdiff'] )  {
          $classe = ' class="nodispo"';
          $recent = '<a class="icon-recent mev formulaire" title="Cette information ne s\'affichera que le '.format_date($r['dispo2']).' à '.substr($r['dispo'],11).'"></a>';
        }
        else  {
          $recent = '<a class="icon-recent formulaire" title="Régler un affichage différé"></a>';
          $r['dispo'] = 0;
        }
        $majpubli = ( $r['cache'] || $r['affdiff'] ) ? '' : 'majpubli';
        // Affichage
        echo <<<FIN

  <article$classe data-id="${r['id']}" data-protection="${r['protection']}" data-edition="${r['edition']}" data-dispo="${r['dispo']}">$iconeprotection$iconeedition
    <h3 class="edition editable titreinfos" data-champ="titre" placeholder="Titre de l'information (non obligatoire)">${r['titre']}</h3>
    <a class="icon-aide" title="Aide pour l'édition de cette information"></a>
    $recent
    <a class="icon-lock formulaire" title="Modifier la protection de l'information"></a> 
    $visible
    <a class="icon-monte"$monte title="Déplacer cette information vers le haut"></a>
    <a class="icon-descend"$descend title="Déplacer cette information vers le bas"></a>
    <a class="icon-supprime" title="Supprimer cette information"></a>
    <div class="editable edithtml $majpubli" data-champ="texte" placeholder="Texte de l'information (obligatoire)">
${r['texte']}
    </div>
  </article>

FIN;
      }
      // Édition possible pour cet élément 
      else  {
        $editionjs = true;
        // Affichage
        echo <<<FIN

  <article data-action="infos" data-id="${r['id']}">
    <h3 class="edition editable" data-champ="titre" placeholder="Titre de l'information (non obligatoire)">${r['titre']}</h3>
    <a class="icon-aide" title="Aide pour l'édition de cette information"></a>
    <div class="editable edithtml majpubli" data-champ="texte" placeholder="Texte de l'information (obligatoire)">
${r['texte']}
    </div>
  </article>

FIN;
      }
    }
    else  {
      // Mode lecture et information éditable : icone et valeur d'édition ($a défini précédemment)
      if ( $r['editable_ml'] )  {
        $icone = "\n    <span class=\"icon-edite affichable\" data-title=\"Vous êtes en mode lecture et voyez cette page comme les ${comptes[$a]}. Cette information est éditable par ce type d'utilisateur.\"></span>";
        $donnees = " data-edition=\"${r['edition']}\"";
      }
      else
        $icone = $donnees = '';
      $titre = ( $r['titre'] ) ? "\n    <h3>${r['titre']}</h3>" : '';
      echo <<<FIN

  <article$donnees>$icone$titre
${r['texte']}
  </article>

FIN;
    }
  }
  $resultat->free();
}
else
  echo "  <article><h2>Cette page est actuellement vide.</h2></article>\n\n";
$mysqli->close();

// Formulaires pour l'édition globale
if ( $edition || $admin )  {

  // Textes affichés sur les éventuelles icônes du titre
  switch ( $page['protection'] )  {
    case 0: break;
    case 32:
      echo "  <div id=\"aide-affprotection\"><strong>Cette page n'est visible que pour les professeurs$textematiere.</strong> Elle n'est pas accessible aux autres utilisateurs.<br> Mais les informations précédées d'un cadenas rouge <span class=\'icon-lock mev\'></span> peuvent apparaître sur la page des <span class='icon-recent'></span>&nbsp;derniers contenus.</div>\n\n";
      break;
    case 1:
      echo "  <div id=\"aide-affprotection\"><strong>Cette page est visible par tous les utilisateurs connectés ayant saisi leur mot de passe, invisible sans connexion.</strong><br> Les icônes cadenas <span class=\"icon-lock\"></span> à gauche de chaque titre d'information indiquent les informations qui ont une protection d'accès différente.<br> <span class=\"mev\">Le cadenas est rouge</span> si l'information est visible sans connexion (via la page des <span class=\"icon-recent\"></span>&nbsp;derniers contenus).<br> Le cadenas est noir si l'information n'est visible que par une partie des utilisateurs connectés.</div>\n\n";
      break;
    default:
      $p = $page['protection']-1;
      $texte = preg_replace('/,([^,]+)$/',' et$1',implode(', les ',array_filter($comptes,function($a) use($p) { return !($p>>$a & 1); },ARRAY_FILTER_USE_KEY)));
      $texte = ( $texte ) ? "En plus des professeurs$textematiere, <strong>cette page est visible par les $texte.</strong>" : "<strong>Cette page n'est visible que par les professeurs$textematiere.</strong>";
      echo "  <div id=\"aide-affprotection\">$texte<br> Les icônes cadenas <span class=\"icon-lock\"></span> à gauche de chaque titre d'information indiquent les informations qui ont une protection d'accès différente.<br> <span class=\"mev\">Le cadenas est rouge</span> si l'information est visible par des utilisateurs n'ayant pas accès à la page (via la page des <span class=\"icon-recent\"></span>&nbsp;derniers contenus).<br> Le cadenas est noir si l'information n'est visible que par une partie des utilisateurs ayant accès à la page.</div>\n\n";
  }
  if ( $page['edition'] )  {
    $e = $page['edition']-1;
    $texte = preg_replace('/,([^,]+)$/',' et$1',implode(', les ',array_filter($comptes,function($a) use($e) { return $e>>$a & 1; },ARRAY_FILTER_USE_KEY)));
    echo "  <div id=\"aide-affedition\">En plus des professeurs$textematiere, <strong>cette page est éditable par les $texte.</strong><br> Ces utilisateurs peuvent ajouter ou retoucher des informations, mais pas modifier les préférences de la page.<br> Les icônes crayon <span class=\"icon-edite\"></span> à gauche de chaque titre d'information indiquent les informations qui ont une possibilité d'édition différente.<br> <span class=\"mev\">Le crayon est rouge</span> si l'information est éditable par des utilisateurs n'ayant pas ce droit sur l'ensemble de la page.<br> Le crayon est noir si l'information n'est éditable que par une partie des utilisateurs pouvant éditer la page.</div>\n\n";
  }

?>

  <form id="form-ajoute" data-action="ajout-info">
    <h3 class="edition">Ajouter une nouvelle information</h3>
    <input class="ligne" type="text" name="titre" size=50 placeholder="Titre de l'information (non obligatoire)">
    <textarea name="texte" class="edithtml ligne" rows="10" cols="100" placeholder="Texte de l'information (obligatoire)"></textarea>
    <p class="ligne"><label for="protection">Accès&nbsp;: </label><select name="protection[]" multiple data-val32="Information invisible"></select></p>
    <p class="ligne"><label for="edition">Édition&nbsp;: </label><select name="edition[]" multiple></select></p>
    <p class="ligne"><label for="affdiff">Affichage différé&nbsp;: </label><input type="checkbox" class="nonbloque" name="affdiff" value="1"></p>
    <p class="ligne"><label for="dispo">Disponibilité &nbsp;: </label><input type="text" name="dispo" size="15"></p>
    <p>Les informations sont toujours visibles et éditables par les professeurs<?php echo $textematiere; ?>.</p>
    <p>Seuls les comptes ayant accès à une information peuvent l'éditer.</p>
  </form>

  <form id="form-supprimeinfos" data-action="supprime-infos">
    <h3 class="edition">Supprimer plusieurs informations</h3>
    <p class="ligne"><label for="infoscachees">Cocher toutes les informations cachées&nbsp;: </label><input type="checkbox" class="nonbloque" name="infoscachees" value="1"></p>
    <table>
      <thead>
        <tr>
          <th>Titre</th>
          <th class="icones"><a class="icon-cocher" title="Tout cocher"></a></th>
        </tr>
      </thead>
      <tbody>
      </tbody>
    </table>
    <p>Les informations cochées seront supprimées si vous validez cette action en cliquant sur le bouton&nbsp;<span class="icon-ok"></span>. Attention, cette suppression sera définitive. Il ne sera pas demandé de confirmation.</p>
  </form>

  <form id="form-lock" data-action="infolock">
    <h3 class="edition">Modifier l'accès à une information</h3>
    <p class="ligne"><label for="protection">Accès&nbsp;: </label><select name="protection[]" multiple data-val32="Information invisible"></select></p>
    <p class="ligne"><label for="edition">Édition&nbsp;: </label><select name="edition[]" multiple></select></p>
    <p>Les informations sont toujours visibles et éditables par les professeurs<?php echo $textematiere; ?>.</p>
    <p>Seuls les comptes ayant accès à une information peuvent l'éditer : l'accès doit inclure l'édition. Si ce n'est pas le cas dans ce que vous réglez ci-dessus, l'édition sera automatiquement réduite pour être incluse à la fois dans l'accès à l'information et dans l'accès à la page.</p>
    <input type="button" class="ligne" value="Réinitialiser aux valeurs de la page">
  </form>

  <form id="form-recent">
    <h3 class="edition">Différer l'affichage</h3>
    <p>Vous pouvez ici programmer l'affichage de l'information. L'information ne sera alors visible que par les professeurs<?php echo $textematiere; ?> avant cette date, et apparaîtra avec les droits prévus après.</p>
    <p>Laissez cette case vide pour désactiver la fonction et rendre immédiatement visible l'information.</p>
    <input class="ligne" type="text" name="dispo" size="15" placeholder="Date de disponibilité">
  </form>

  <form id="form-prefs" data-action="pages">
    <h3 class="edition">Modifier les préférences de la page</h3>
    <p class="ligne"><label for="titre">Titre&nbsp;: </label><input type="text" name="titre" value="<?php echo $page['titre']; ?>" size="80" placeholder="Titre de la page (obligatoire)"></p>
    <p class="ligne"><label for="nom">Nom dans le menu&nbsp;: </label><input type="text" name="nom" value="<?php echo $page['nom']; ?>" size="50" placeholder="Nom de la page dans le menu (obligatoire)"></p>
    <p class="ligne"><label for="cle">Clé dans l'adresse&nbsp;: </label><input type="text" name="cle" value="<?php echo ($p = strpos($page['cle'],'/'))?substr($page['cle'],$p+1):$page['cle']; ?>" size="30" placeholder="Mot-clé sans majuscule, sans accent, sans espace (obligatoire)"></p>
    <p class="ligne"><label for="protection">Accès&nbsp;: </label><select name="protection[]" multiple data-val32="Page invisible"></select></p>
    <p class="ligne"><label for="edition">Édition&nbsp;: </label><select name="edition[]" multiple></select></p>
    <p class="ligne"><label for="propagation">Propager ce choix d'accès à chaque information de la page&nbsp;: </label><input type="checkbox" class="nonbloque" name="propagation" value="1"></p>
    <p class="ligne"><label for="bandeau">Texte de début&nbsp;:</label></p>
    <textarea name="bandeau" rows="2" cols="100" placeholder="Texte apparaissant au début de la page (non obligatoire)"><?php echo $page['bandeau']; ?></textarea>
    <p>Cette page est nécessairement visible et éditable par les professeurs<?php echo $textematiere; ?>.</p>
  </form>
  
  <div id="aide-page">
    <h3>Aide et explications</h3>
    <p>Il est possible ici d'ajouter une information, de modifier les informations existantes ou de modifier les préférences de la page.</p>
    <p>La page d'accueil du site ne peut pas être supprimée ou déplacée, mais il est possible de créer d'autres pages d'informations, éventuellement liées à une matière et apparaissant ainsi dans le menu. Ces pages peuvent alors être déplacées ou supprimées. Pour ce faire, il faut aller à la <a href="pages">gestion des pages</a>.</p>
    <p>Chaque page peut être vidée de toutes ses informations en un clic sur la page de <a href="pages">gestion des pages</a>.</p>
    <p>Les titres et les informations dans chaque zone indiquée par des pointillés sont modifiables individuellement, en cliquant sur les boutons <span class="icon-edite"></span> et en validant avec le bouton <span class="icon-ok"></span> qui apparaît alors.</p>
    <p>Les trois boutons généraux permettent de&nbsp;:</p>
    <ul>
      <li><span class="icon-ajoute"></span>&nbsp;: ajouter une nouvelle information.</li>
      <li><span class="icon-supprimeinfos"></span>&nbsp;: supprimer plusieurs informations, à sélectionner.</li>
      <li><span class="icon-prefs"></span>&nbsp;: modifier les préférences de la page pour modifier le titre, le nom dans le menu, l'accès à la page.</li>
      <li><span class="icon-lecture"></span>&nbsp;: accéder à la modification du «&nbsp;mode de lecture&nbsp;», qui permet de voir le contenu de cette page comme la voit un autre type de compte, notamment pour vérifier les accès en lecture et en écriture à la page et aux informations.</li>
    </ul>
    <h4>Réglage d'accès global en lecture à l'agenda</h4>
    <p>L'accès en lecture à chaque page et à chaque information peut être protégé indépendamment. Il est modifiable en cliquant sur le bouton <span class="icon-prefs"></span> en haut à droite pour l'accès à la page et sur les boutons <span class="icon-lock"></span> à droite de chaque cadre pour les informations. Trois catégories de choix existent&nbsp;:</p>
    <ul>
      <li><em>Accès public</em>&nbsp;: page ou information visible par tout visiteur, sans identification. Les moteurs de recherche ont accès aux textes des informations. Une page en accès public n'a pas de cadenas <span class="icon-lock"></span> affiché à droite de son titre.</li>
      <li><em>Utilisateurs identifiés</em>&nbsp;: page visible uniquement par les utilisateurs identifiés à déterminer, en fonction de leur type de compte. Pour une page, un cadenas <span class="icon-lock"></span> est alors affiché à droite de son titre. En cliquant dessus, un texte listant les comptes ayant accès en lecture à la page s'affiche.</li>
      <li><em>Page/Information invisible</em>&nbsp;: page entièrement invisible pour les utilisateurs autres que les professeurs (éventuellement associés à la matière à laquelle la page est liée). Un cadenas <span class="icon-locktotal"></span> est alors affiché dans le titre de la page.</li>
    </ul>
    <h4>Réglage spécifique d'accès en lecture des informations</h4>
    <p>Les informations peuvent avoir le même réglage d'accès en lecture que la page ou non.</p>
    <ul>
      <li>Une information visible par tous les utilisateurs ayant accès à la page n'a pas de cadenas à gauche de son titre. C'est le cas le plus classique et le réglage par défaut à la création d'une nouvelle information.</li>
      <li>Une information visible par une partie des utilisateurs ayant accès à la page est marquée par un cadenas noir <span class="icon-lock"></span> à gauche de son titre. Ceci permet par exemple, sur une page visible sans identification (sans taper son mot de passe), de positionner une information réservée aux utilisateurs identifiés, notamment si elle contient des noms d'élèves ou de colleurs.</li>
      <li>Une information visible par des utilisateurs n'ayant pas accès à la page est marquée par un cadenas rouge <span class="icon-lock mev"></span> à gauche de son titre. Ce réglage, a priori rarement utile, peut permettre d'ajouter une information dans la page des <span class="icon-recent"></span>&nbsp;derniers contenus pour des utilisateurs qui ne verraient pas la page d'information.</li>
    </ul>
    <p>Un clic sur le cadenas à gauche du titre de chaque information permet de voir le détail du réglage d'accès en lecture à la page et à l'information.</p>
    <p>Une information peut aussi être rendue rapidement et simplement invisible des utilisateurs non éditeurs de la page en cliquant sur le bouton <span class="icon-cache"></span>.</p>
    <h4>Réglage d'accès global en écriture à l'agenda</h4>
    <p>L'accès en écriture à chaque page et à chaque information est modifiable comme pour la lecture. Deux catégories de choix existent&nbsp;:</p>
    <ul>
      <li><em>Professeurs uniquement</em>&nbsp;: page ou information modifiable uniquement par les professeurs (éventuellement associés à la matière à laquelle la page est liée).</li>
      <li><em>Droits étendus à ...</em>&nbsp;: page ou information modifiable par les professeurs et les comptes choisis.</li>
    </ul>
    <p>L'accès en écriture à une page permet d'obtenir le bouton général d'ajout d'information <span class="icon-ajoute"></span> ainsi que tous les boutons spécifiques à chaque information. La modification des préférences de la page reste réservée aux professeurs (éventuellement associés à la matière à laquelle la page est liée) et aux comptes administrateurs.</p>
    <p>Pour un utilisateur qui n'a pas accès en écriture à la page, l'accès en écriture à une information permet d'obtenir la capacité de modifier le titre et le texte de l'information. Cela ne permet pas de modifier les réglages d'accès en lecture et écriture de l'information.</p>
    <p>Par défaut, seuls les professeurs (éventuellement associés à la matière à laquelle la page est liée) ont accès en écriture sur l'ensemble de la page et à chaque information. Dans ce cas, il n'y a pas de crayon <span class="icon-edite"></span> à droite du titre de la page et à gauche des titres des informations.</p>
    <p>Si d'autres utilisateurs ont accès en écriture à la page, un crayon <span class="icon-edite"></span> est alors affiché à droite de son titre. En cliquant dessus, un texte listant les comptes ayant accès en écriture à la page s'affiche.</p>
    <h4>Réglage spécifique d'accès en écriture des informations</h4>
    <p>Les informations peuvent avoir le même réglage d'accès en écriture que la page ou non.</p>
    <ul>
      <li>Une information éditable par tous les utilisateurs pouvant éditer la page n'a pas de cadenas à gauche de son titre. C'est le cas le plus classique et le réglage par défaut à la création d'une nouvelle information.</li>
      <li>Une information éditable par des utilisateurs n'ayant pas accès en écriture à la page est marquée par un crayon rouge <span class="icon-edite mev"></span> à gauche de son titre. Ceci permet par exemple, sur une page éditable uniquement par les professeurs, de positionner une information éditable par les élèves.</li>
      <li>Une information éditable par moins d'utilisateurs que ceux pouvant éditer la page est marquée par un crayon noir <span class="icon-edite"></span> à gauche de son titre. Ceci peut permettre par exemple, sur une page que pourraient éditer les colleurs, de positionner une information qu'ils ne pourraient pas modifier ni enlever.</li>
    </ul>
    <p>Un clic sur cet éventuel crayon permet de voir le détail du réglage d'accès en écriture à la page et à l'information.</p>
    <h4>Accès minimal et imbrication des accès</h4>
    <p>Il n'est pas possible d'empêcher les professeurs (éventuellement associés à la matière à laquelle la page est liée) de voir et d'éditer toutes les informations d'une page.</p>
    <p>Pour une page liée à une matière, les accès en lecture ou en écriture sont obligatoirement restreint par l'association des utilisateurs à la matière correspondant. Ce n'est pas le cas pour la page d'accueil comme pour toutes les pages non liées à une matière.</p>
    <p>L'accès en lecture à une page n'a aucune dépendance.</p>
    <p>L'accès en écriture à une page n'est possible que pour les utilisateurs ayant accès à la page en lecture.</p>
    <p>L'accès en lecture à une information n'a aucune dépendance (il ne dépend pas de l'accès en lecture à la page).</p>
    <p>L'accès en écriture à une information n'est possible que pour les utilisateurs ayant accès à la page et à l'information en lecture (il ne dépend pas de l'accès en écriture à la page).</p>
    <p>Il n'y a pas nécessairement de lien entre l'accès en écriture à une page et à une information de cette page.</p>
    <h4>Lire aussi...</h4>
    <p>Une autre <span class="icon-aide"></span>&nbsp;aide dans le cadre de chaque information donne d'autres précisions sur les différents boutons modifiant chaque information. Une autre aide est aussi disponible dans chaque action. N'hésitez pas à les consulter&nbsp;!</p>
  </div>

  <div id="aide-infos">
    <h3>Aide et explications</h3>
    <h4>Modification du contenu</h4>
    <p>Le titre et le texte de chaque information sont modifiables séparément, en cliquant sur le bouton <span class="icon-edite"></span> correspondant. Chaque contenu éditable est indiqué par une bordure en pointillés.</p>
    <p>Une fois le texte édité, il est validé en appuyant sur Entrée dans le cas des titres ou sur le bouton <span class="icon-ok"></span>. Au contraire, un clic sur le bouton <span class="icon-annule"></span> annule l'édition.</p>
    <p>Le titre d'une information peut rester vide, mais pas le texte.</p>
    <p>Le texte doit être finalement formaté en HTML, mais les boutons d'édition vous aideront à cela (pas besoin de s'y connaître !). Ils permettent aussi d'ajouter des liens vers les autres pages du site, vers des documents du site, vers d'autres pages sur le web... L'insertion de ressources de type image, pdf ou vidéo, est aussi possible. Le dernier de ces boutons d'édition affiche une aide sur leur fonctionnement.</p>
    <h4>Autres modifications</h4>
    <p>D'autres modifications sont possibles à l'aide des boutons disponibles pour chaque information&nbsp;:</p>
    <ul>
      <li><span class="icon-supprime"></span>&nbsp;: supprimer l'information (une confirmation sera demandée)</li>
      <li><span class="icon-monte"></span>&nbsp;: remonter l'information d'un cran</li>
      <li><span class="icon-descend"></span>&nbsp;: descendre l'information d'un cran</li>
      <li><span class="icon-cache"></span>&nbsp;: rendre l'information invisible. Elle ne sera alors visible que par les utilisateurs ayant l'accès en écriture à la page, par défaut les professeurs (éventuellement associés à la matière à laquelle la page est liée). Cela peut être utile pour une information qui n'est plus valable mais que l'on souhaite conserver, ou pour une information qui est encore à compléter.</li>
      <li><span class="icon-montre"></span>&nbsp;: afficher l'information à tous les utilisateurs ayant accès en lecture à la page</li>
      <li><span class="icon-lock"></span>&nbsp;: gérer l'accès en lecture et en écriture à l'information</li>
      <li><span class="icon-recent"></span>&nbsp;: définir une date d'affichage différé</li>
    </ul>
    <h4>Réglage des accès en lecture et en écriture</h4>
    <p>L'accès en lecture et en écriture à chaque information peut être spécifié, indépendamment de celui à la page. Trois catégories de choix sont possibles en lecture&nbsp;:</p>
    <ul>
      <li><em>Accès public</em>&nbsp;: information visible par tout visiteur, sans identification. Les moteurs de recherche ont accès aux textes des informations.</li>
      <li><em>Utilisateurs identifiés</em>&nbsp;: information visible uniquement par les utilisateurs identifiés à déterminer, en fonction de leur type de compte.</li>
      <li><em>Information invisible</em>&nbsp;: information entièrement invisible pour les utilisateurs autres que les utilisateurs ayant accès en écriture à la page.</li>
    </ul>
    <p>Pour l'accès en écriture, deux catégories de choix existent&nbsp;:</p>
    <ul>
      <li><em>Professeurs uniquement</em>&nbsp;: information modifiable uniquement par les professeurs (éventuellement associés à la matière à laquelle la page est liée).</li>
      <li><em>Droits étendus à ...</em>&nbsp;: information modifiable par les professeurs et les comptes choisis.</li>
    </ul>
    <p>Les boutons <span class="icon-montre"></span> et <span class="icon-cache"></span> permettent de modifier rapidement la visibilité de l'information&nbsp;: le bouton <span class="icon-cache"></span> rend immédiatement l'information invisible, le bouton <span class="icon-montre"></span> la fait apparaître avec les réglages d'accès en lecture et écriture de la page.</p>
    <p>Par défaut, l'accès de la page, réglable dans les préférences de la page par le bouton <span class="icon-prefs"></span>, est appliqué à la création d'une information. Ce réglage peut être retrouvé facilement en cliquant sur le bouton «&nbsp;<em>Réinitialiser aux valeurs de la page</em>&nbsp;» dans le formulaire ouvert à l'aide du bouton <span class="icon-lock"></span>.</p>
    <h4>Visibilité du réglage des accès en lecture et en écriture</h4>
    <p>Une information invisible est présentée sur fond gris. Elle n'est visible et éditable que par les utilisateurs ayant le droit d'éditer la page.</p>
    <ul>
      <li>Une information visible par tous les utilisateurs pouvant voir la page et éditable par tous les utilisateurs pouvant éditer la page n'a ni cadenas ni crayon à gauche de son titre. C'est le cas le plus classique et le réglage par défaut à la création d'une nouvelle information.</li>
      <li>Une information visible par une partie des utilisateurs ayant accès à la page est marquée par un cadenas noir <span class="icon-lock"></span> à gauche de son titre. Ceci permet par exemple, sur une page visible sans identification (sans taper son mot de passe), de positionner une information réservée aux utilisateurs identifiés, notamment si elle contient des noms d'élèves ou de colleurs.</li>
      <li>Une information visible par des utilisateurs n'ayant pas accès à la page est marquée par un cadenas rouge <span class="icon-lock mev"></span> à gauche de son titre. Ce réglage, a priori rarement utile, peut permettre d'ajouter une information dans la page des <span class="icon-recent"></span>&nbsp;derniers contenus pour des utilisateurs qui ne verraient pas la page d'information.</li>
      <li>Une information éditable par des utilisateurs n'ayant pas accès en écriture à la page est marquée par un crayon rouge <span class="icon-edite mev"></span> à gauche de son titre. Ceci permet par exemple, sur une page éditable uniquement par les professeurs, de positionner une information éditable par les élèves.</li>
      <li>Une information éditable par moins d'utilisateurs que ceux pouvant éditer la page est marquée par un crayon noir <span class="icon-edite"></span> à gauche de son titre. Ceci peut permettre par exemple, sur une page que pourraient éditer les colleurs, de positionner une information qu'ils ne pourraient pas modifier ni enlever.</li>
    </ul>
    <p>Un clic sur cet éventuel cadenas permet de voir le détail du réglage d'accès en lecture à la page et à l'information. Un clic sur cet éventuel crayon permet de voir le détail du réglage d'accès en écriture à la page et à l'information.</p>
    <p>Il n'est pas possible d'empêcher les professeurs (éventuellement associés à la matière à laquelle la page est liée) de voir et d'éditer toutes les informations d'une page. Toutes les combinaisons d'accès ne sont pas possibles, mais l'accès en écriture sera réduit si nécessaire lors de la validation d'une demande de modification.</p>
    <h4>Affichage différé</h4>
    <p>Il est possible de différer l'affichage en cliquant sur le bouton <span class="icon-recent"></span>. L'information reste alors invisible jusqu'à la date-heure définie. Elle apparaîtra à cette date avec les réglages d'accès en lecture et en écriture qui sont choisis, simultanément sur la page et sur la page des <span class="icon-recent"></span>&nbsp;derniers contenus.</p>
    <p>Les informations en affichage différé sont indiquées par un fond plus clair et un bouton horloge rouge <span class="icon-recent mev"></span>. Un survol à la souris de ce bouton permet de voir à quelle heure apparaîtra l'information.</p>
  </div>

  <div id="aide-ajoute">
    <h3>Aide et explications</h3>
    <p>Ce formulaire permet de créer une nouvelle information. Il sera validé par un clic sur <span class="icon-ok"></span>, et abandonné (donc supprimé) par un clic sur <span class="icon-ferme"></span>.</p>
    <p>Le <em>titre</em> sera affiché, un peu plus gros, au-dessus de l'information. Ce doit être une simple ligne de texte, plutôt court. Il peut rester vide, il n'y aura alors pas de titre affiché.</p>
    <p>Le <em>texte</em> doit être au final formaté en HTML, mais les boutons d'édition vous aident à cela (pas besoin de s'y connaître !). Ils permettent aussi d'ajouter des liens vers les autres pages du site, vers des documents du site, vers d'autres pages sur le web... L'insertion de ressources de type image, pdf ou vidéo, est aussi possible. Le dernier de ces boutons d'édition affiche une aide sur leur fonctionnement.</p>
    <h4>Réglages des accès en lecture et en écriture</h4>
    <p>L'<em>accès</em> en lecture et en écriture (<em>édition</em>) à cette nouvelle information est réglable. Les valeurs sélectionnées par défaut sont celles de la page, mais peuvent être modifiées.</p>
    <p>Pour l'accès en lecture, trois catégories de choix sont possibles&nbsp;:</p>
    <ul>
      <li><em>Accès public</em>&nbsp;: information visible par tout visiteur, sans identification. Les moteurs de recherche ont accès aux textes des informations.</li>
      <li><em>Utilisateurs identifiés</em>&nbsp;: information visible uniquement par les utilisateurs identifiés à déterminer, en fonction de leur type de compte.</li>
      <li><em>Information invisible</em>&nbsp;: information entièrement invisible pour les utilisateurs autres que les utilisateurs ayant accès en écriture à la page.</li>
    </ul>
    <p>Pour l'accès en écriture, deux catégories de choix existent&nbsp;:</p>
    <ul>
      <li><em>Professeurs uniquement</em>&nbsp;: information modifiable uniquement par les professeurs (éventuellement associés à la matière à laquelle la page est liée).</li>
      <li><em>Droits étendus à ...</em>&nbsp;: information modifiable par les professeurs et les comptes choisis.</li>
    </ul>
    <p>Les professeurs (éventuellement associés à la matière à laquelle la page est liée) peuvent obligatoirement voir et éditer les informations. On ne peut qu'ajouter d'autres types de comptes. Toutes les combinaisons d'accès ne sont pas possibles, l'accès en écriture sera réduit si nécessaire lors de la validation du formulaire selon les règles suivantes&nbsp;:</p>
    <ul>
      <li>L'accès en lecture à une information n'a aucune dépendance (il ne dépend pas de l'accès en lecture à la page).</li>
      <li>L'accès en écriture à une information n'est possible que pour les utilisateurs ayant accès à la page et à l'information en lecture (il ne dépend pas de l'accès en écriture à la page).</li>
    </ul>
    <p>Pour une information invisible, le réglage de l'accès en écriture disparaît et devra être précisé si on lui redonne une visibilité. Cela peut être fait ultérieurement à l'aide du bouton <span class="icon-cadenas"></span> spécifique à l'information, ou être automatiquement égalisé aux droits d'accès en écriture de la page si la publication est réalisée par le bouton <span class="icon-montre"></span>.</p>
    <p>L'information sera affichée en haut de page, mais pourra être déplacée ultérieurement à l'aide des boutons <span class="icon-descend"></span> et <span class="icon-monte"></span>.</p>
    <p>Il est possible de choisir un <em>affichage différé</em> en cochant cette case&nbsp;: l'information restera alors invisible jusqu'à la date-heure de <em>disponibilité</em> définie (nécessairement dans le futur), puis apparaîtra avec les réglages d'accès en lecture et en écriture qui sont choisis, simultanément sur la page et sur la page des <span class="icon-recent"></span>&nbsp;derniers contenus.</p>
    <p>Les informations en affichage différé sont indiquées par un fond plus clair et un bouton horloge rouge <span class="icon-recent mev"></span>. Un survol à la souris de ce bouton permet de voir à quelle heure apparaîtra l'information.</p>
  </div>

  <div id="aide-supprimeinfos">
    <h3>Aide et explications</h3>
    <p>Ce formulaire permet de supprimer en une seule fois un grand nombre d'informations. Il sera validé par un clic sur <span class="icon-ok"></span>, et abandonné (donc supprimé) par un clic sur <span class="icon-ferme"></span>.</p>
    <p>Toutes les informations présentes sur la page sont listées dans le tableau, identifiables par leur <em>titre</em>. Les informations cachées ont un <em>titre</em> en italique.</p>
    <p>Chaque information cochée (avec une case cochée sur la ligne) sera supprimée après validation du formulaire.</p>
    <p>Il est possible de facilement sélectionner les informations cachées uniquement en cochant la case <em>Cocher toutes les informations cachées</em> située au-dessus du tableau.</p>
    <p>Il est possible de facilement sélectionner toutes les informations en cochant la case <span class="icon-cocher"></span> située en haut du tableau.</p>
    <p>Comme toutes les actions de suppression, cette action est définitive.</p>
    <p>La suppression est immédiate dès le clic que <span class="icon-ok"></span>. Aucune confirmation ne sera demandée.</p>
  </div>

  <div id="aide-lock">
    <h3>Aide et explications</h3>
    <p>Ce formulaire permet de modifier les réglages d'accès en lecture et en écriture à cette information. Il sera validé par un clic sur <span class="icon-ok"></span>, et abandonné (donc supprimé) par un clic sur <span class="icon-ferme"></span>.</p>
    <p>Pour l'accès en lecture, trois catégories de choix sont possibles&nbsp;:</p>
    <ul>
      <li><em>Accès public</em>&nbsp;: information visible par tout visiteur, sans identification. Les moteurs de recherche ont accès aux textes des informations.</li>
      <li><em>Utilisateurs identifiés</em>&nbsp;: information visible uniquement par les utilisateurs identifiés à déterminer, en fonction de leur type de compte.</li>
      <li><em>Information invisible</em>&nbsp;: information entièrement invisible pour les utilisateurs autres que les utilisateurs ayant accès en écriture à la page.</li>
    </ul>
    <p>Pour l'accès en écriture, deux catégories de choix existent&nbsp;:</p>
    <ul>
      <li><em>Professeurs uniquement</em>&nbsp;: information modifiable uniquement par les professeurs (éventuellement associés à la matière à laquelle la page est liée).</li>
      <li><em>Droits étendus à ...</em>&nbsp;: information modifiable par les professeurs et les comptes choisis.</li>
    </ul>
    <p>Les professeurs (éventuellement associés à la matière à laquelle la page est liée) peuvent obligatoirement voir et éditer les informations. On ne peut qu'ajouter d'autres types de comptes. Toutes les combinaisons d'accès ne sont pas possibles, l'accès en écriture sera réduit si nécessaire lors de la validation du formulaire selon les règles suivantes&nbsp;:</p>
    <ul>
      <li>L'accès en lecture à une information n'a aucune dépendance (il ne dépend pas de l'accès en lecture à la page).</li>
      <li>L'accès en écriture à une information n'est possible que pour les utilisateurs ayant accès à la page et à l'information en lecture (il ne dépend pas de l'accès en écriture à la page).</li>
    </ul>
    <p>Pour une information invisible, le réglage de l'accès en écriture disparaît et devra être précisé si on lui redonne une visibilité. Cela peut être fait ultérieurement en revenant ici, ou être automatiquement égalisé aux droits d'accès en écriture de la page si la publication est réalisée par le bouton <span class="icon-montre"></span>.</p>
  </div>

  <div id="aide-recent">
    <h3>Aide et explications</h3>
    <p>Ce formulaire permet de modifier l'affichage différé de l'information. Il sera validé par un clic sur <span class="icon-ok"></span>, et abandonné (donc supprimé) par un clic sur <span class="icon-ferme"></span>.</p>
    <p>Si une date de <em>disponibilité</em> est choisie (nécessairement dans le futur), l'information restera alors invisible jusqu'à la date-heure définie, puis apparaîtra avec les réglages d'accès en lecture et en écriture actuellement définis, simultanément sur la page et sur la page des <span class="icon-recent"></span>&nbsp;derniers contenus.</p>
    <p>Il est donc impossible de définir un affichage différé pour une information invisible. Il faut lui définir d'abord un réglage d'accès en lecture et en écriture.</p>
    <p>Valider une <em>disponibilité</em> vide annule l'affichage différé&nbsp;: l'information est publiée immédiatement avec les réglages d'accès actuellement définis.</p>
    <p>Les informations en affichage différé sont indiquées par un fond plus clair et un bouton horloge rouge <span class="icon-recent mev"></span>. Un survol à la souris de ce bouton permet de voir à quelle heure apparaîtra l'information.</p>
  </div>

  <div id="aide-prefs">
    <h3>Aide et explications</h3>
    <p>Ce formulaire permet de modifier les préférences de cette page d'informations. Il sera validé par un clic sur <span class="icon-ok"></span>, et abandonné (donc supprimé) par un clic sur <span class="icon-ferme"></span>. On peut y modifier&nbsp;:</p>
    <ul>
      <li>le <em>titre</em> qui sera affiché en haut de page et dans la barre de titre du navigateur. Par exemple, «&nbsp;À propos du TIPE&nbsp;».</li>
      <li>le <em>nom dans le menu</em> qui est affiché dans le menu en tant que lien vers la page. Il est préférable qu'il rentre sur une ligne, il faut donc le choisir assez court. Par exemple, «&nbsp;Informations TIPE&nbsp;».</li>
      <li>la <em>clé dans l'adresse</em> qui est un mot-clé utilisé uniquement dans l'adresse de la page. Par convention, il vaut mieux que ce soit un mot unique, court et sans majuscule. Par exemple, «&nbsp;tipe&nbsp;». La clé doit obligatoirement être unique (deux pages ne peuvent pas avoir la même clé).</li>
      <li>le <em>texte de début</em>, qui sera affiché au-dessus des informations de la page. Il s'agit d'une ou deux phrases maximum. Il n'est affiché que si la page contient des informations. Cette case peut être laissée vide.</li>
      <li>l'<em>accès</em> en lecture et en écriture (<em>édition</em>) à la page (voir ci-dessous).</li>
    </ul>
    <p>La case à cocher <em>Propager ce choix d'accès à chaque information</em> permet de modifier les réglages d'accès en lecture et en écriture individuel de chaque information, en les alignant avec ceux choisis pour la page. Les informations dont l'accès en lecture est différent de celui de la page sont repérées par un cadenas <span class="icon-lock"></span> à gauche de leur titre, celles dont l'accès en écriture est différent de celui de la page sont repérées par un crayon <span class="icon-edite"></span>. Valider ce formulaire en cochant cette case doit supprimer toutes les différences de réglage, donc supprimer toutes les icônes à gauche des titre d'informations.</p>
    <p>Si cette case n'est pas cochée, le changement de réglage d'accès à la page n'a donc aucune influence directe sur la visibilité des informations, qui restent éventuellement accessibles sur la page des <span class="icon-recent"></span>&nbsp;derniers contenus. Cela peut par contre modifier le réglage d'accès en écriture des informations. L'accès en écriture à une information n'est possible que pour les utilisateurs ayant accès à la page et à l'information en lecture (il ne dépend pas de l'accès en écriture à la page).</p>
    <h4>Réglages des accès en lecture et en écriture</h4>
    <p>Pour l'accès en lecture, trois catégories de choix sont possibles&nbsp;:</p>
    <ul>
      <li><em>Accès public</em>&nbsp;: page visible par tout visiteur, sans identification. Les moteurs de recherche ont accès aux textes des informations qui ne sont pas davantage protégées.</li>
      <li><em>Utilisateurs identifiés</em>&nbsp;: page visible uniquement par les utilisateurs identifiés à déterminer, en fonction de leur type de compte.</li>
      <li><em>Page invisible</em>&nbsp;: page invisible, sauf pour les professeurs (éventuellement associés à la matière à laquelle la page est liée). Pour tous les autres, la page d'accueil est affichée à la place.</li>
    </ul>
    <p>Pour l'accès en écriture, deux catégories de choix existent&nbsp;:</p>
    <ul>
      <li><em>Professeurs uniquement</em>&nbsp;: page modifiable uniquement par les professeurs (éventuellement associés à la matière à laquelle la page est liée), valeur par défaut.</li>
      <li><em>Droits étendus à ...</em>&nbsp;: information modifiable par les professeurs et les comptes choisis.</li>
    </ul>
    <p>Les professeurs (éventuellement associés à la matière à laquelle la page est liée) peuvent obligatoirement voir et éditer les informations. On ne peut qu'ajouter d'autres types de comptes. Seuls les utilisateurs qui peuvent voir la page peuvent aussi l'éditer.</p>
    <p>L'accès en écriture à la page correspond à obtenir toutes les possibilités de modification, sauf ce formulaire, qui reste accessible uniquement par les professeurs (éventuellement associés à la matière à laquelle la page est liée) et les comptes administrateurs.</p>
  </div>

<?php
}
// Aide spéciale pour éditeurs d'informations uniquement 
elseif ( $editionjs )  {
?>

  <div id="aide-infos">
    <h3>Aide et explications</h3>
    <p>Le titre et le texte de cette information sont modifiables séparément, en cliquant sur le bouton <span class="icon-edite"></span> correspondant. Chaque contenu éditable est indiqué par une bordure en pointillés.</p>
    <p>Une fois le texte édité, il est validé en appuyant sur Entrée dans le cas des titres ou sur le bouton <span class="icon-ok"></span>. Au contraire, un clic sur le bouton <span class="icon-annule"></span> annule l'édition.</p>
    <p>Le titre d'une information peut rester vide.</p>
    <p>Le texte doit être finalement formaté en HTML, mais les boutons d'édition vous aideront à cela (pas besoin de s'y connaître !). Ils permettent aussi d'ajouter des liens vers les autres pages du site, vers des documents du site, vers d'autres pages sur le web... L'insertion de ressources de type image, pdf ou vidéo, est aussi possible. Le dernier de ces boutons d'édition affiche une aide sur leur fonctionnement.</p>
  </div>

<?php  
}

fin($editionjs,$mathjax,$script ?? '');
?>
