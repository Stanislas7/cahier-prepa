<?php
// Sécurité
define('OK',1);
// Configuration
include('config.php');
// Fonctions
include('fonctions.php');

// Note : l'affichage des heures de début/fin est conditionné par le champ
// deb_fin_pour de la table cdt-types (donc du type de séance choisi) :
// 0 : Début seulement (jour date à debut : type (demigroupe))
// 1 : Début et fin (jour date de debut à fin : type (demigroupe))
// 2 : Pas d'horaire mais date d'échéance (jour date : type pour (demigroupe))
// 3 : Pas d'horaire ni date d'échéance (jour date : type (demigroupe))
// 4 : Entrée journalière (jour date)
// 5 : Entrée hebdomadaire (pas de titre)

////////////////////////////////////////
// Validation de la requête : matière //
////////////////////////////////////////

// Recherche de la matière concernée, variable $matiere
// Si $_REQUEST['cle'] existe, on la cherche dans les matières disponibles.
// cdt=0 : cahier de texte vide, à afficher uniquement pour les profs concernés
// cdt=1 : cahier de texte utilisé, à afficher pour les utilisateurs associés à la matière 
// cdt=2 : cahier de texte désativé, pas d'affichage
// cdt_protection permet de restreindre l'accès
// La gestion de l'accès est entièrement géré par la fonction acces
$mysqli = connectsql();
$resultat = $mysqli->query('SELECT id, cle, nom, cdt_protection AS protection FROM matieres WHERE cdt < 2');
if ( $resultat->num_rows )  {
  if ( !empty($_REQUEST) )  {
    while ( $r = $resultat->fetch_assoc() )
      if ( isset($_REQUEST[$r['cle']]) )  {
        $matiere = $r;
        $mid = $matiere['id'];
        $cle = $matiere['cle'];
        break;
      }
  }
  $resultat->free();
  // Si aucune matière trouvée
  if ( !isset($matiere) )  {
    debut($mysqli,'Cahier de texte','Mauvais paramètre d\'accès à cette page.',$autorisation,' ');
    $mysqli->close();
    fin();
  }
}
// Si aucune matière présentant son cahier de texte n'est enregistrée
else  {
  debut($mysqli,'Cahier de texte','Cette page ne contient aucune information.',$autorisation,' ');
  $mysqli->close();
  fin();
}

/////////////////////////////
// Vérification de l'accès //
/////////////////////////////
// $edition vaut 2 si professeur de la matière, 0 sinon
// MathJax désactivé par défaut
$admin = $_SESSION['admin'] ?? false;
$editionjs = $donnees = $mathjax = false;
$edition = acces($matiere['protection'],$mid,"Cahier de texte - ${matiere['nom']}","cdt?$cle",$mysqli);
if ( $edition || $admin )  {
  $editionjs = true;
  $donnees = array('action'=>'cdt','matiere'=>$mid,'protection'=>$matiere['protection'],'edition'=>0,'css'=>'datetimepicker');
}
$mode_lecture = ( $edition || $admin ) ? $_SESSION['mode_lecture'] : 0;

//////////////////////////////////////////////////////
// Gestion globale d'une matière -- profs seulement //
//////////////////////////////////////////////////////
// cdt-raccourcis.php et cdt-seances contiennent fin()
if ( $edition )  {
  if ( isset($_REQUEST['raccourcis']) )
    include('cdt-raccourcis.php');
  if ( isset($_REQUEST['seances']) )
    include('cdt-seances.php');
}

//////////////////////////////////////////////////////
// Validation de la requête : semaine(s) à afficher //
//////////////////////////////////////////////////////

// Récupération des semaines et du nombre de semaines
$resultat = $mysqli->query("SELECT semaines.id, DATE_FORMAT(debut,'%w%Y%m%e') AS debut, DATE_FORMAT(debut,'%y%v') AS semaine, nom AS vacances 
                            FROM semaines LEFT JOIN vacances ON semaines.vacances = vacances.id ORDER BY semaines.id");
$select_semaines = "\n      <option value=\"0\">Toute l'année</option>";
$semaines = $semaines_id = array(0=>'');
while ( $r = $resultat->fetch_assoc() )  {
  $semaines[] = $r;
  $semaines_id[] = $r['semaine'];
  $select_semaines .= "\n      <option value=\"${r['id']}\">".( $r['vacances'] ?: format_date($r['debut']) ).'</option>';
}
$resultat->free();
$nmax = count($semaines);

// Récupération des types de séances
// $select_seances est utilisé dans le bandeau de recherche
// $seances est utilisé pour la validation de recherche de séance
$resultat = $mysqli->query("SELECT cle FROM `cdt-types` WHERE matiere = $mid AND nb ORDER BY ordre");
$select_seances = "\n      <option value=\"tout\">Toutes les séances</option>";
$seances = array();
if ( $resultat->num_rows )  {
  while ( $r = $resultat->fetch_assoc() )  {
    $select_seances .= "\n        <option value=\"${r['cle']}\">Les ${r['cle']}</option>";
    $seances[] = $r['cle'];
  }
  $resultat->free();
}

// Recherche sur du texte
// Recherche et type/semaine sont exclusifs
// n peut être nul pour afficher toute l'année
// $lien sert pour les boutons de la barre de recherche
$recherche = $lien_n = $lien_voir = $requete = '';
if ( $_REQUEST['recherche'] ?? '' )  {
  $requete = 'AND cdt.texte LIKE \'%'.$mysqli->real_escape_string($recherche = htmlspecialchars($_REQUEST['recherche'])).'%\'';
  $n = 0;
  $nb = $nmax;
}
// Vue de tout le cahier de texte de l'année
elseif ( isset($_REQUEST['tout']) )  {
  $requete = '';
  $n = 0;
  $nb = $nmax;
}
// Vue d'une (ou plusieurs) semaine précise
elseif ( isset($_REQUEST['n']) && ctype_digit($n = $_REQUEST['n']) && ( $n >= 0 ) && ( $n <= $nmax ) )  {
  $requete = "AND cdt.semaine >= $n";
  $lien_n = "&amp;n=$n";
  // Si $n est nul, "toute l'année" sélectionnée
  if ( !$n )
    $nb = $nmax;
  // Nombre d'éléments vus par défaut : 2 en mode édition, 1 sinon
  elseif ( !isset($_REQUEST['nb']) || !ctype_digit($nb = $_REQUEST['nb']) || ( $nb < 1 ) )
    $nb = 2 - !$edition;
}
// Vue de la semaine en cours à partir du lundi
// Vue de la semaine précédente et de la semaine en cours jusqu'au vendredi
// $n est false si non trouvé (hors année scolaire)
elseif ( ( $n = array_search(date('yW', strtotime('Monday this week',time()-86400)),$semaines_id) ) !== false )  {
  $requete = "AND cdt.semaine >= $n";
  $nb = ( date('N') > 5 ) ? 1 : 2;
}
// Type de séances demandées
if ( isset($_REQUEST['voir']) && in_array($seance = $_REQUEST['voir'],$seances,true) )  {
  $select_seances = str_replace("\"$seance\"","\"$seance\" selected",$select_seances);
  $requete .= " AND t.cle = '$seance'";
  $lien_voir = "&amp;voir=$seance";
}

////////////
/// HTML ///
////////////
debut($mysqli,"Cahier de texte - ${matiere['nom']}",$message,$autorisation,"cdt?$cle",$donnees ?? false);

// Formulaire de la demande des semaines à afficher
if ( $n )
  $select_semaines = str_replace("\"$n\"","\"$n\" selected",$select_semaines);

$boutons = "
  <p id=\"recherchecdt\" class=\"topbarre\">
    <a class=\"icon-precedent\" href=\"?$cle$lien_voir&amp;n=".max(1,$n-1)."\" title=\"Semaine précédente\"></a>
    <a class=\"icon-suivant\" href=\"?$cle$lien_voir&amp;n=".min($n+1,$nmax)."\" title=\"Semaine suivante\"></a>
    <a class=\"icon-voirtout\" href=\"?$cle&amp;tout\" title=\"Voir l'ensemble du cahier de texte\"></a>
    <select id=\"voir\" onchange=\"window.location='?$cle&amp;voir='+this.value+'$lien_n';\">$select_seances
    </select>
    <select id=\"semaines\" onchange=\"window.location='?$cle$lien_voir&amp;n='+this.value;\">$select_semaines
    </select>
    <span class=\"icon-recherche\" onclick=\"window.location='?$cle&amp;recherche='+$('.topbarre input').val();\"></span>
    <input type=\"text\" value=\"$recherche\" onchange=\"window.location='?$cle&amp;recherche='+this.value;\" placeholder=\"Rechercher un mot...\">
  </p>
";

// Affichage public sans édition
if ( !$edition || $mode_lecture )  {
  
  // Si mode lecture (modification de $autorisation inutile, non utilisée dans la suite)
  if ( $mode_lecture )  {
    $editionjs = true;
    echo "\n\n  <div id=\"icones\">\n    <a class=\"icon-lecture mev\" title=\"Modifier le mode de lecture\"></a>\n  </div>\n\n";
    if ( $matiere['protection'] && ( !( $mode_lecture-1 ) || ( ( $matiere['protection']-1 ) >> ( $mode_lecture-2 ) & 1 ) ) )  {
      echo "\n  <article><h2>Cette page n'est pas autorisée pour ce type d'utilisateur.</h2></article>\n\n";
      $mysqli->close();
      fin(true);
    }
  }
  elseif ( ( $autorisation == 5 ) || $admin )
    echo "\n\n  <div id=\"icones\">\n    <a class=\"icon-lecture\" title=\"Modifier le mode de lecture\"></a>\n  </div>\n\n";

  echo $boutons;
  if ( $n !== false )  {
    // Affichage des éléments du cahier de texte recherchés
    $resultat = $mysqli->query("SELECT cdt.semaine, DATE_FORMAT(cdt.jour,'%w') AS jour, DATE_FORMAT(cdt.jour,'%d/%m/%Y') AS date,
                                TIME_FORMAT(cdt.h_debut,'%kh%i') AS h_debut, TIME_FORMAT(cdt.h_fin,'%kh%i') AS h_fin, DATE_FORMAT(cdt.pour,'%d/%m/%Y') AS pour,
                                cdt.texte, IF(cdt.demigroupe,' (en demi-groupe)','') AS demigroupe, t.titre, t.deb_fin_pour
                                FROM cdt LEFT JOIN `cdt-types` AS t ON t.id = cdt.type
                                WHERE cdt.matiere = $mid AND cdt.cache = 0 $requete AND dispo < NOW()
                                ORDER BY cdt.jour,cdt.pour,cdt.h_debut,cdt.h_fin,cdt.type");
    $mysqli->close();
    if ( $resultat->num_rows )  {
      $compteur = 0;
      $semaine = ( $n > 0 ) ? $n-1 : 0;
      $jours = array('','Lundi','Mardi','Mercredi','Jeudi','Vendredi','Samedi');
      while ( $r = $resultat->fetch_assoc() )  {
        // Nouvelles semaines éventuelles
        while ( $semaine < $r['semaine'] )  {
          // On sort avant de commencer la nouvelle semaine si $compteur était 
          // égal à $nb : on vient de finir la $nb semaine hors vacances.
          if ( $compteur >= $nb )
            break 2;
          $semaine = $semaine+1;
          if ( $semaine == $r['semaine'] )
            $compteur = $compteur+1;
          if ( !$recherche || ( $semaine == $r['semaine'] ) )
            echo "\n  <h3>".( ( $v = $semaines[$semaine]['vacances'] ) ? ucfirst(format_date($semaines[$semaine]['debut']))."&nbsp;: $v" : 'Semaine du '.format_date($semaines[$semaine]['debut']) ).'</h3>';
        }
        // Élément du cahier de texte
        $mathjax = $mathjax ?: boolval(strpos($r['texte'],'$')+strpos($r['texte'],'\\'));
        switch ( $r['deb_fin_pour'] )  {
          case 0: $titre = "${jours[$r['jour']]} ${r['date']} à ${r['h_debut']}&nbsp;: ${r['titre']}${r['demigroupe']}"; break;
          case 1: $titre = "${jours[$r['jour']]} ${r['date']} de ${r['h_debut']} à ${r['h_fin']}&nbsp;: ${r['titre']}${r['demigroupe']}"; break;
          case 2: $titre = "${jours[$r['jour']]} ${r['date']}&nbsp;: ${r['titre']} pour le ${r['pour']}${r['demigroupe']}"; break;
          case 3: $titre = "${jours[$r['jour']]} ${r['date']}&nbsp;: ${r['titre']}${r['demigroupe']}"; break;
          case 4: $titre = "${jours[$r['jour']]} ${r['date']}"; break;
        }
        $titre = ( $r['deb_fin_pour'] < 5 ) ? "\n    <h3 class=\"titrecdt\">$titre</h3>" : '';
        echo <<<FIN
      
  <article>$titre
${r['texte']}
  </article>

FIN;
      }
      $resultat->free();
    }
    else
      echo "\n  <article><h2>Aucun résultat n'a été trouvé pour cette recherche.</h2></article>\n";
  }
  else
    echo "\n  <article><h2>L'année est terminée... Bonnes vacances&nbsp;!</h2>\n  <p><a href=\"?$cle&amp;tout\">Revoir tout le cahier de texte</a></p></article>\n";
}
    
// Affichage professeur éditeur
else  {
  echo <<<FIN
  
  <div id="icones" data-action="page">
    <a class="icon-ajoute formulaire" title="Ajouter un nouvel élément du cahier de texte"></a>
    <a class="icon-prefs formulaire" title="Modifier les réglages du cahier de texte"></a>
    <a class="icon-lecture" title="Modifier le mode de lecture"></a>
    <a class="icon-aide" title="Aide pour les modifications des éléments du cahier de texte"></a>
  </div>
$boutons
FIN;
  if ( $n !== false )  {
    
    // Affichage des éléments du cahier de texte recherchés
    $resultat = $mysqli->query("SELECT cdt.id, cdt.semaine, DATE_FORMAT(cdt.jour,'%w') AS jour, DATE_FORMAT(cdt.jour,'%d/%m/%Y') AS date,
                                TIME_FORMAT(cdt.h_debut,'%kh%i') AS h_debut, TIME_FORMAT(cdt.h_fin,'%kh%i') AS h_fin, DATE_FORMAT(cdt.pour,'%d/%m/%Y') AS pour,
                                cdt.texte, IF(cdt.demigroupe,' (en demi-groupe)','') AS demigroupe,
                                cdt.cache, t.id AS tid, t.titre, t.deb_fin_pour,
                                IF(dispo>NOW(),1,0) AS affdiff, DATE_FORMAT(dispo,'%d/%m/%Y %kh%i') AS dispo, DATE_FORMAT(dispo,'%w%Y%m%e') AS dispo2
                                FROM cdt LEFT JOIN `cdt-types` AS t ON t.id = cdt.type
                                WHERE cdt.matiere = $mid $requete
                                ORDER BY cdt.jour,cdt.pour,cdt.h_debut,cdt.h_fin,cdt.type");
    if ( $resultat->num_rows )  {
      $compteur = 0;
      $semaine = ( $n > 0 ) ? $n-1 : 0;
      $jours = array('','Lundi','Mardi','Mercredi','Jeudi','Vendredi','Samedi');
      while ( $r = $resultat->fetch_assoc() )  {
        // Nouvelles semaines éventuelles
        while ( $semaine < $r['semaine'] )  {
          // On sort avant de commencer la nouvelle semaine si $compteur était 
          // égal à $nb : on vient de finir la $nb semaine hors vacances.
          if ( $compteur >= $nb )
            break 2;
          $semaine = $semaine+1;
          if ( $semaine == $r['semaine'] )
            $compteur = $compteur+1;
          if ( !$recherche || ( $semaine == $r['semaine'] ) )
            echo "\n  <h3>".( ( $v = $semaines[$semaine]['vacances'] ) ? ucfirst(format_date($semaines[$semaine]['debut']))."&nbsp;: $v" : 'Semaine du '.format_date($semaines[$semaine]['debut']) ).'</h3>';
        }
        // Élément du cahier de texte
        $mathjax = $mathjax ?: boolval(strpos($r['texte'],'$')+strpos($r['texte'],'\\'));
        switch ( $r['deb_fin_pour'] )  {
          case 0: $titre = "${jours[$r['jour']]} ${r['date']} à ${r['h_debut']}&nbsp;: ${r['titre']}${r['demigroupe']}"; break;
          case 1: $titre = "${jours[$r['jour']]} ${r['date']} de ${r['h_debut']} à ${r['h_fin']}&nbsp;: ${r['titre']}${r['demigroupe']}"; break;
          case 2: $titre = "${jours[$r['jour']]} ${r['date']}&nbsp;: ${r['titre']} pour le ${r['pour']}${r['demigroupe']}"; break;
          case 3: $titre = "${jours[$r['jour']]} ${r['date']}&nbsp;: ${r['titre']}${r['demigroupe']}"; break;
          case 4: $titre = "${jours[$r['jour']]} ${r['date']}"; break;
          case 5: $titre = '[Entrée hebdomadaire]';
        }
        $visible = ( $r['cache'] ) ? '<a class="icon-montre" title="Afficher l\'élément du cahier de texte sur la partie publique"></a>' : '<a class="icon-cache" title="Rendre invisible l\'élément du cahier de texte sur la partie publique"></a>';
        $demigroupe = ( $r['demigroupe'] ) ? 1 : 0;
        if ( $r['affdiff'] )  {
          $classe = ' class="nodispo"';
          $recent = '<a class="icon-recent mev formulaire" title="Cet élément ne s\'affichera que le '.format_date($r['dispo2']).' à '.substr($r['dispo'],11).'"></a>';
        }
        else  {
          $classe = ( $r['cache'] ) ? ' class="cache"' : '';
          $recent = '<a class="icon-recent formulaire" title="Régler un affichage différé"></a>';
          $r['dispo'] = 0;
        }
        echo <<<FIN

  <article$classe data-id="${r['id']}" data-dispo="${r['dispo']}">
    <a class="icon-aide" title="Aide pour les modifications du cahier de texte"></a>
    $recent
    $visible
    <a class="icon-supprime" title="Supprimer cet élément du cahier de texte"></a>
    <p class="titrecdt edition" data-donnees='{"tid":${r['tid']},"jour":"${r['date']}","h_debut":"${r['h_debut']}","h_fin":"${r['h_fin']}","pour":"${r['pour']}","demigroupe":$demigroupe}'>$titre</p>
    <div class="editable edithtml" data-champ="texte" placeholder="Texte de l'élément du cahier de texte">
${r['texte']}
    </div>
  </article>

FIN;
      }
      $resultat->free();
    }
    else
      echo "\n  <article><h2>Aucun résultat n'a été trouvé pour cette recherche.</h2></article>\n\n";
  }
  else
    echo "\n  <article><h2>L'année est terminée... Bonnes vacances&nbsp;!</h2>\n  <p><a href=\"?$cle&amp;tout\">Revoir tout le cahier de texte</a></p></article>\n";

  // Récupération des boutons
  $resultat = $mysqli->query("SELECT id, nom, jour, type, demigroupe, template,
                              TIME_FORMAT(h_debut,'%kh%i') AS h_debut, TIME_FORMAT(h_fin,'%kh%i') AS h_fin
                              FROM `cdt-seances` WHERE matiere = $mid ORDER BY ordre");
  $select_raccourcis = '';
  $raccourcis = array();
  if ( $resultat->num_rows )  {
    while ( $r = $resultat->fetch_assoc() )  {
      $select_raccourcis .= "<option value=\"${r['id']}\">${r['nom']}</option>";
      $raccourcis[$r['id']] = array('tid'=>$r['type'],'jour'=>$r['jour'],'h_debut'=>$r['h_debut'],'h_fin'=>$r['h_fin'],'demigroupe'=>$r['demigroupe'],'template'=>$r['template']);
    }
    $resultat->free();
  }
  $select_raccourcis = ( $select_raccourcis ) ? '<option value="0"></option>'.$select_raccourcis : '<option value="0">Aucun raccourci défini</option>';

  // Nouvelle récupération des types de séances, nécessaire car les types
  // "vides" ne sont pas récupérés précédemment
  $resultat = $mysqli->query("SELECT id, titre, deb_fin_pour FROM `cdt-types` WHERE matiere = $mid ORDER BY ordre");
  $mysqli->close();
  $select_seances = '';
  $seances = array();
  if ( $resultat->num_rows )  {
    while ( $r = $resultat->fetch_assoc() )  {
      $select_seances .= "<option value=\"${r['id']}\">${r['titre']}</option>";
      $seances[$r['id']] = $r['deb_fin_pour'];
    }
    $resultat->free();
  }

  // Aide et formulaire d'ajout
?>

  <form id="form-cdt">
    <p class="ligne">
      <label for="racourci">Raccourci&nbsp;</label><a class="icon-edite" href="cdt?<?php echo $cle; ?>&raccourcis" title="Éditer les raccourcis de séances"></a>&nbsp;:
      <select name="raccourci" class="nonbloque"><?php echo $select_raccourcis; ?></select>
    </p>
    <p class="ligne">
      <label for="tid">Séance&nbsp;</label><a class="icon-edite" href="cdt?<?php echo $cle; ?>&seances"></a>&nbsp;:
      <select name="tid"><?php echo $select_seances; ?></select>
    </p>
    <p class="ligne"><label for="jour">Jour&nbsp;: </label><input type="text" name="jour" value="" size="8"></p>
    <p class="ligne"><label for="h_debut">Heure de début&nbsp;: </label><input type="text" name="h_debut" value="" size="5"></p>
    <p class="ligne"><label for="h_fin">Heure de fin&nbsp;: </label><input type="text" name="h_fin" value="" size="5"></p>
    <p class="ligne"><label for="pour">Pour le&nbsp;: </label><input type="text" name="pour" value="" size="8"></p>
    <p class="ligne"><label for="demigroupe">Séance en demi-groupe&nbsp;: </label>
      <select name="demigroupe"><option value="0">Classe entière</option><option value="1">Demi-groupe</option></select>
    </p>
  </form>
  
  <form id="form-ajoute">
    <h3 class="edition">Nouvel élément du cahier de texte</h3>
    <p class="ligne">
      <label for="racourci">Raccourci&nbsp;</label><a class="icon-edite" href="cdt?<?php echo $cle; ?>&raccourcis" title="Éditer les raccourcis de séances"></a>&nbsp;:
      <select name="raccourci" class="nonbloque"><?php echo $select_raccourcis; ?></select>
    </p>
    <p class="ligne">
      <label for="tid">Séance&nbsp;</label><a class="icon-edite" href="cdt?<?php echo $cle; ?>&seances"></a>&nbsp;:
      <select name="tid"><?php echo $select_seances; ?></select>
    </p>
    <p class="ligne"><label for="jour">Jour&nbsp;: </label><input type="text" name="jour" value="" size="8"></p>
    <p class="ligne"><label for="h_debut">Heure de début&nbsp;: </label><input type="text" name="h_debut" value="" size="5"></p>
    <p class="ligne"><label for="h_fin">Heure de fin&nbsp;: </label><input type="text" name="h_fin" value="" size="5"></p>
    <p class="ligne"><label for="pour">Pour le&nbsp;: </label><input type="text" name="pour" value="" size="8"></p>
    <p class="ligne"><label for="demigroupe">Séance en demi-groupe&nbsp;: </label>
      <select name="demigroupe"><option value="0">Classe entière</option><option value="1">Demi-groupe</option></select>
    </p>
    <textarea name="texte" class="edithtml" rows="10" cols="100" placeholder="Texte de l'élément du cahier de texte (obligatoire)"></textarea>
    <p class="ligne"><label for="affdiff">Affichage différé&nbsp;: </label><input type="checkbox" class="nonbloque" name="affdiff" value="1"></p>
    <p class="ligne"><label for="dispo">Disponibilité &nbsp;: </label><input type="text" name="dispo" size="15"></p>
    <p class="ligne"><label for="cache">Ne pas publier immédiatement&nbsp;: </label><input type="checkbox" name="cache" value="1"></p>
  </form>

  <form id="form-recent">
    <h3 class="edition">Différer l'affichage</h3>
    <p>Vous pouvez ici programmer l'affichage de cet élément du cahier de texte. Il ne sera alors visible que par les professeurs de la matière avant cette date, et apparaîtra après cette date pour tous les utilisateurs ayant accès au cahier de texte.</p>
    <p>Laissez cette case vide pour désactiver la fonction et rendre immédiatement visible l'élément du cahier de texte.</p>
    <input class="ligne" type="text" name="dispo" size="15" placeholder="Date de disponibilité">
  </form>

  <form id="form-prefs" data-action="prefsmatiere">
    <h3 class="edition">Réglages du cahier de texte en <?php echo $matiere['nom']; ?></h3>
    <p class="ligne"><label for="cdt_protection">Accès&nbsp;: </label><select name="cdt_protection[]" multiple data-val32="Professeurs associés à la matière seulement"></select></p>
    <p>Pour désactiver complètement cette fonction, il faut aller sur la page de <a href="matieres">gestion des matières</a>.</p>
<?php if ( $_SESSION['admin'] )  { ?>
    <p>Pour modifier les utilisateurs concernés par cette matière, il faut vous rendre à la <a href="utilisateurs-matieres">gestion des associations utilisateurs-matières</a>.</p>
<?php } ?>
    <h3>Modification des séances</h3>
    <p>Vous pouvez modifier, spécifiquement à chaque matière&nbsp;:</p>
    <ul>
      <li>les noms des différents types de séances et l'affichage des horaires correspondant (heure de début, heure de fin, date d'échéance)</li>
      <li>des boutons de raccourci qui vous permettent de remplir plus rapidement des éléments du cahier de texte, avec un choix automatique du type de séance, du jour de la semaine, de l'horaire...</li>
    </ul>
    <input onclick="location.href='cdt?<?php echo $cle; ?>&amp;seances'" type="button" class="ligne" value="Modifier les types de séances">
    <input onclick="location.href='cdt?<?php echo $cle; ?>&amp;raccourcis'" type="button" class="ligne" value="Modifier les boutons de raccourcis">
  </form>
  
  <script type="text/javascript">
    seances = <?php echo json_encode($seances); ?>;
    raccourcis = <?php echo json_encode($raccourcis); ?>;
    jours = ['Dimanche','Lundi','Mardi','Mercredi','Jeudi','Vendredi','Samedi'];
  </script>

  <div id="aide-page">
    <h3>Aide et explications</h3>
    <p>Il est possible ici d'ajouter et de modifier des éléments du cahier de texte. On peut également consulter le cahier de texte, sélectionner une semaine, un type de séance, ou effectuer une recherche de texte.</p>
    <p>Chaque élément du cahier de texte contient une date, éventuellement des horaires, un type de séance, et un texte.</p>
    <p>Les horaires et le texte de chaque élément du cahier de texte, apparaissant dans une zone indiquée par des pointillés, sont modifiables individuellement en cliquant sur les boutons <span class="icon-edite"></span> et en validant avec le bouton <span class="icon-ok"></span> qui apparaît alors.</p>
    <p>Les trois boutons généraux, en haut à droite de la page, permettent de&nbsp;:</p>
    <ul>
      <li><span class="icon-ajoute"></span>&nbsp;: ouvrir un formulaire pour ajouter un nouvel élément du cahier de texte.</li>
      <li><span class="icon-prefs"></span>&nbsp;: ouvrir un formulaire pour modifier l'accès au cahier de texte.</li>
      <li><span class="icon-lecture"></span>&nbsp;: accéder à la modification du «&nbsp;mode de lecture&nbsp;», qui permet de voir le contenu de cette page comme la voit un autre type de compte, notamment pour vérifier l'accès en lecture à cette page.</li>
    </ul>
    <h4>Gestion de l'accès</h4>
    <p>L'accès au cahier de texte peut être protégé. Trois catégories de choix sont possibles&nbsp;:</p>
    <ul>
      <li><em>Accès public</em>&nbsp;: cahier visible par tout visiteur, sans identification. Les moteurs de recherche ont accès aux textes des éléments. Une page en accès public n'a pas de cadenas <span class="icon-lock"></span> affiché à droite de son titre.</li>
      <li><em>Utilisateurs identifiés</em>&nbsp;: cahier visible uniquement par les utilisateurs identifiés à déterminer, en fonction de leur type de compte et des matières auxquelles ils sont associés. Un cadenas <span class="icon-lock"></span> est alors affiché à droite du titre de la page. Les associations entre utilisateurs et matières sont modifiables <?php echo $_SESSION['admin'] ? 'sur la page de <a href="utilisateurs-matieres">gestion utilisateurs-matieres</a>' : 'par les administrateurs du Cahier' ; ?>.</li>
      <li><em>Fonction désactivée</em>&nbsp;: le cahier de texte pour cette matière est complètement indisponible. La fonction n'apparaît plus dans le menu, y compris pour vous..</li>
    </ul>
    <p>Pour désactiver complètement cette fonction, il faut aller sur la page de <a href="matieres">gestion des matières</a>.</p>
    <h4>Suppression de tous les éléments du cahier de texte</h4>
    <p>L'ensemble du cahier de texte est supprimable en un clic à la page de <a href="matieres">gestion des matières</a>.</p>
    <h4>Affichage différé</h4>
    <p>Il est possible de différer l'affichage d'un élément du cahier de texte en cliquant sur le bouton <span class="icon-recent"></span>. L'élément reste alors invisible jusqu'à la date-heure définie, puis apparaîtra simultanément sur cette page et sur la page des derniers contenus (lien <span class="icon-recent"></span> du menu), pour les utilisateurs autorisés.</p>
    <p>Les éléments du cahier de texte en affichage différé sont indiqués par un fond plus clair et un bouton horloge rouge <span class="icon-recent mev"></span>. Un survol à la souris de ce bouton permet de voir à quelle heure apparaîtra l'élément.</p>
    <h4>Impression/récupération</h4>
    <p>Le cahier de texte est très facilement imprimable à partir de la fenêtre qui apparaît en cliquant sur le bouton <span class="icon-lecture"></span> du menu ou avec la commande d'impression de votre navigateur.</p>
    <p>Dans les deux cas, tout ce que ne correspond pas au cahier de texte (menu de gauche, marquages en pointillés, icônes de modification) disparaît à l'impression.</p>
    <p>Passer par le bouton <span class="icon-lecture"></span> permet de modifier temporairement le titre, utile pour ajouter l'année ou la classe.</p>
    <p>Le document produit, que vous pourrez conserver en papier ou en pdf, peut constituer un document utilisable pour vous ou pour une inspection.</p>
    <p>Le cahier de texte n'est pas récupérable facilement en fichier éditable. La commande «&nbsp;enregistrer sous&nbsp;» de votre navigateur ne donnera pas de très bon résultats.</p>
    <h4>Lire aussi...</h4>
    <p>Une autre <span class="icon-aide"></span>&nbsp;aide dans le cadre de chaque élément du cahier de texte donne d'autres précisions sur les différents boutons modifiant chaque information. Une autre aide est aussi disponible dans chaque action. N'hésitez pas à les consulter&nbsp;!</p>
  </div>

  <div id="aide-cdt">
    <h3>Aide et explications</h3>
    <h4>Modification du contenu</h4>
    <p>Les horaires et le texte de chaque élément du cahier de texte existant sont modifiables séparément, en cliquant sur le bouton <span class="icon-edite"></span>. Chaque contenu éditable est indiqué par une bordure en pointillés.</p>
    <p>Le texte doit être finalement formaté en HTML, mais les boutons d'édition vous aideront à cela (pas besoin de s'y connaître !). Ils permettent aussi d'ajouter des liens vers les autres pages du site, vers des documents du site, vers d'autres pages sur le web... L'insertion de ressources de type image, pdf ou vidéo, est aussi possible. Le dernier de ces boutons d'édition affiche une aide sur leur fonctionnement.</p>
    <p>Une fois le texte édité, il est validé en appuyant sur le bouton <span class="icon-ok"></span>. Au contraire, un clic sur le bouton <span class="icon-annule"></span> annule l'édition.</p>
    <h4>Autres modifications</h4>
    <p>D'autres modifications sont possibles à l'aide des boutons disponibles pour chaque élément du cahier de texte&nbsp;:</p>
    <ul>
      <li><span class="icon-supprime"></span>&nbsp;: supprimer l'élément du cahier de texte (une confirmation sera demandée)</li>
      <li><span class="icon-cache"></span>&nbsp;: rendre l'élément du cahier de texte invisible. Il ne sera alors visible que par les professeurs associés à la matière. Cela peut être utile pour entrer son cahier de texte à l'avance ou si l'on se rend compte d'une grosse erreur que l'on souhaite corriger à l'abri des regards par exemple.</li>
      <li><span class="icon-montre"></span>&nbsp;: afficher l'élément du cahier de texte à tous les utilisateurs autorisés</li>
      <li><span class="icon-recent"></span>&nbsp;: définir une date d'affichage différé</li>
    </ul>
    <h4>Affichage différé</h4>
    <p>Il est possible de différer l'affichage d'un élément du cahier de texte en cliquant sur le bouton <span class="icon-recent"></span>. L'élément reste alors invisible jusqu'à la date-heure définie, puis apparaîtra simultanément sur cette page et sur la page des derniers contenus (lien <span class="icon-recent"></span> du menu), pour les utilisateurs autorisés.</p>
    <p>Les éléments du cahier de texte en affichage différé sont indiqués par un fond plus clair et un bouton horloge rouge <span class="icon-recent mev"></span>. Un survol à la souris de ce bouton permet de voir à quelle heure apparaîtra l'élément.</p>
    <h4>Types de séances</h4>
    <p>Les éléments du cahier de texte peuvent être catégorisées par type de séance. Ces types de séances sont modifiables sur la page de <a href="cdt?<?php echo $cle; ?>&seances">gestion de types de séances</a>, accessible dans le menu ou par le bouton <span class="icon-edite"></span> situé devant le menu de sélection du type de séance.</p>
    <p>Les types de séances sont indépendants d'une matière à l'autre, vous avez tout intérêt à les modifier pour les faire correspondre à vos besoins personnels. Paramétrer ces types permet de&nbsp;</p>
    <ul>
      <li>obtenir des titres correspondant correctement à la séance effectuée</li>
      <li>faciliter les recherches dans le cahier de texte</li>
      <li>modifier l'affichage des horaires (horaires de début et de fin, de début seulement, ou pas d'horaire)</li>
    </ul>
    <p>Il est possible de spécifier des types de séances du genre "Entrée quotidienne" ou "Entrée hebdomadaire" si vous préférez saisir votre cahier de texte sans préciser exactement les horaires ou les jours.</p>
    <p>Lorsque vous modifiez le jour/horaire d'un élément du cahier de texte, modifier la séance peut modifier immédiatement l'affichage des champs suivants, selon les réglages effectués à la <a href="cdt?<?php echo $cle; ?>&seances">gestion des types de séances</a>.</p>
    <h4>Raccourcis</h4>
    <p>Il est possible de définir des <em>raccourcis</em>, propres à chaque matière, qui pré-rempliront entièrement un nouvel élément de cahier de texte ou des horaires d'une séance déjà saisie. On peut par exemple disposer d'un raccourci «&nbsp;Cours du lundi&nbsp;» qui permettra de régler automatiquement le type de séance à Cours, le jour de la séance au lundi de la semaine en cours, les heures de début et fin à 8h et 10h.</p>
    <p>On peut définir dans chaque raccourci un modèle de texte qui sera automatiquement utilisé en cas de nouvel élément, à modifier ensuite avant publication. C'est très pratique si vous écrivez toujours avec une même structure les éléments correspondant à une séance de la semaine.</p>
    <p>Ces raccourcis sont modifiables sur la page de <a href="cdt?<?php echo $cle; ?>&raccourcis">gestion des raccourcis</a>, accessible dans le menu ou par le bouton <span class="icon-edite"></span> situé devant le menu de sélection du raccourci dans le formulaire de saisie des horaires.</p>
    <h4>Modification du texte</h4>
    <p>Le texte doit être finalement formaté en HTML, mais les boutons d'édition vous aideront à cela (pas besoin de s'y connaître !). Ils permettent aussi d'ajouter des liens vers les autres pages du site, vers des documents du site, vers d'autres pages sur le web... L'insertion de ressources de type image, pdf ou vidéo, est aussi possible. Le dernier de ces boutons d'édition affiche une aide sur leur fonctionnement.</p>
  </div>

  <div id="aide-ajoute">
    <h3>Aide et explications</h3>
    <p>Ce formulaire permet de créer un nouvel élément du cahier de texte. Il sera validé par un clic sur <span class="icon-ok"></span>, et abandonné (donc supprimé) par un clic sur <span class="icon-annule"></span>.</p>
    <p>La <em>séance</em> correspond au type de séance. Modifier la séance peut modifier immédiatement l'affichage des champs suivants, selon les réglages effectués à la page de <a href="cdt?<?php echo $cle; ?>&seances">gestion des types de séances</a>.</p>
    <p>Le texte doit être finalement formaté en HTML, mais les boutons d'édition vous aideront à cela (pas besoin de s'y connaître !). Ils permettent aussi d'ajouter des liens vers les autres pages du site, vers des documents du site, vers d'autres pages sur le web... L'insertion de ressources de type image, pdf ou vidéo, est aussi possible. Le dernier de ces boutons d'édition affiche une aide sur leur fonctionnement.</p>
    <h4>Affichage immédiat ou non</h4>
    <p>La case à cocher <em>Ne pas publier immédiatement</em> permet de garder temporairement invisible cet élément du cahier de texte, par exemple pour le diffuser ultérieurement. Les éléments invisibles n'apparaissent qu'aux professeurs associés à la matière et sont indiqués par un fond gris clair. Ils peuvent être plus tard affichés aux utilisateurs autorisés à l'aide du bouton <span class="icon-montre"></span> correspondant à l'élément.</p>
    <p>Il est possible de choisir un <em>affichage différé</em> en cochant cette case&nbsp;: l'élément du cahier de texte reste alors invisible jusqu'à la date-heure définie, puis apparaîtra simultanément sur cette page et sur la page des derniers contenus (lien <span class="icon-recent"></span> du menu), pour les utilisateurs autorisés.</p>
    <p>Les éléments du cahier de texte en affichage différé sont indiqués par un fond plus clair et un bouton horloge rouge <span class="icon-recent mev"></span>. Un survol à la souris de ce bouton permet de voir à quelle heure apparaîtra l'élément.</p>
    <h4>Types de séances</h4>
    <p>Les éléments du cahier de texte peuvent être catégorisées par type de séance. Ces types de séances sont modifiables sur la page de <a href="cdt?<?php echo $cle; ?>&seances">gestion de types de séances</a>, accessible dans le menu ou par le bouton <span class="icon-edite"></span> situé devant le menu de sélection du type de séance.</p>
    <p>Les types de séances sont indépendants d'une matière à l'autre, vous avez tout intérêt à les modifier pour les faire correspondre à vos besoins personnels. Paramétrer ces types permet de&nbsp;</p>
    <ul>
      <li>obtenir des titres correspondant correctement à la séance effectuée</li>
      <li>faciliter les recherches dans le cahier de texte</li>
      <li>modifier l'affichage des horaires (horaires de début et de fin, de début seulement, ou pas d'horaire)</li>
    </ul>
    <p>Il est possible de spécifier des types de séances du genre "Entrée quotidienne" ou "Entrée hebdomadaire" si vous préférez saisir votre cahier de texte sans préciser exactement les horaires ou les jours.</p>
    <h4>Raccourcis</h4>
    <p>Il est possible de définir des <em>raccourcis</em>, propres à chaque matière, qui pré-rempliront entièrement un nouvel élément de cahier de texte. On peut par exemple disposer d'un raccourci «&nbsp;Cours du lundi&nbsp;» qui permettra de régler automatiquement le type de séance à Cours, le jour de la séance au lundi de la semaine en cours, les heures de début et fin à 8h et 10h.</p>
    <p>On peut définir dans chaque raccourci un modèle de texte qui sera automatiquement utilisé pour pré-remplir le <em>texte</em> de l'élément, à modifier ensuite avant publication. C'est très pratique si vous écrivez toujours avec une même structure les éléments correspondant à une séance de la semaine.</p>
    <p>Ces raccourcis sont modifiables sur la page de <a href="cdt?<?php echo $cle; ?>&raccourcis">gestion des raccourcis</a>, accessible dans le menu ou par le bouton <span class="icon-edite"></span> situé devant le menu de sélection du raccourci dans le formulaire de saisie des horaires.</p>
  </div>
  
  <div id="aide-prefs">
    <h3>Aide et explications</h3>
    <p>Ce formulaire permet de modifier l'accès au cahier de texte en <?php echo $matiere['nom']; ?>. Il sera validé par un clic sur <span class="icon-ok"></span>, et abandonné (donc supprimé) par un clic sur <span class="icon-ferme"></span>.</p>
    <p>Trois catégories de choix sont possibles&nbsp;:</p>
    <ul>
      <li><em>Accès public</em>&nbsp;: cahier visible par tout visiteur, sans identification. Les moteurs de recherche ont accès aux textes des éléments. Une page en accès public n'a pas de cadenas <span class="icon-lock"></span> affiché à droite de son titre.</li>
      <li><em>Utilisateurs identifiés</em>&nbsp;: cahier visible uniquement par les utilisateurs identifiés à déterminer, en fonction de leur type de compte et des matières auxquelles ils sont associés. Un cadenas <span class="icon-lock"></span> est alors affiché à droite du titre de la page. Les associations entre utilisateurs et matières sont modifiables <?php echo $_SESSION['admin'] ? 'sur la page de <a href="utilisateurs-matieres">gestion utilisateurs-matieres</a>' : 'par les administrateurs du Cahier' ; ?>.</li>
      <li><em>Fonction désactivée</em>&nbsp;: le cahier de texte pour cette matière est complètement indisponible. La fonction n'apparaît plus dans le menu, y compris pour vous..</li>
    </ul>
    <p>Si vous désactivez ici cette fonction, vous ne pourrez plus utiliser cette page. Les éléments du cahier de texte ne seront pas supprimés, le choix est réversible. Vous pourrez réactiver cette fonction à la page de <a href="matieres">gestion des matières</a>.</p>
    <p>Le lien vers cette page dans le menu est visible avant identification, uniquement s'il y a des éléments à afficher. Il disparaît après identification pour les utilisateurs n'ayant pas accès à cette page.</p>
    <h4>Suppression de tous les éléments du cahier de texte</h4>
    <p>L'ensemble du cahier de texte est supprimable en un clic à la page de <a href="matieres">gestion des matières</a>.</p>
  </div>

  <div id="aide-recent">
    <h3>Aide et explications</h3>
    <p>Ce formulaire permet de modifier l'affichage différé de l'élément du cahier de texte correspondant. Il sera validé par un clic sur <span class="icon-ok"></span>, et abandonné (donc supprimé) par un clic sur <span class="icon-ferme"></span>.</p>
    <p>Si une date de <em>disponibilité</em> est choisie (nécessairement dans le futur), l'élément restera alors invisible jusqu'à la date-heure définie, puis apparaîtra simultanément sur la page et sur la page des derniers contenus (lien <span class="icon-recent"></span> du menu), pour les utilisateurs autorisés.</p>
    <p>Valider une <em>disponibilité</em> vide annule l'affichage différé&nbsp;: l'élément est publié immédiatement.</p>
    <p>Les éléments du cahier de texte en affichage différé sont indiqués par un fond plus clair et un bouton horloge rouge <span class="icon-recent mev"></span>. Un survol à la souris de ce bouton permet de voir à quelle heure apparaîtra l'élément.</p>
  </div>

<?php
}

if ( $edition || $admin )  {
  // Textes affichés sur les éventuelles icônes du titre
  switch ( $matiere['protection'] )  {
    case 0: break;
    case 32:
      echo "  <div id=\"aide-affprotection\"><strong>Ce cahier de texte n'est visible que pour les professeurs associés à la matière.</strong> Il n'est pas accessible aux autres utilisateurs.</div>\n\n";
      break;
    case 1:
      echo "  <div id=\"aide-affprotection\"><strong>Ce cahier de texte est visible par tous les utilisateurs connectés ayant saisi leur mot de passe, invisibles sans connexion</strong>.</div>\n\n";
      break;
    default:
      $p = $matiere['protection']-1;
      $comptes = array('invités','élèves','colleurs','comptes de type lycée','professeurs (même non associés à la matière)');
      $texte = preg_replace('/,([^,]+)$/',' et$1',implode(', les ',array_filter($comptes,function($a) use($p) { return !($p>>$a & 1); },ARRAY_FILTER_USE_KEY)));
      echo "  <div id=\"aide-affprotection\">En plus des professeurs associés à la matière, <strong>ce cahier de texte est visible par les $texte</strong>.</div>\n\n";
  }
}

fin($editionjs,$mathjax,$edition ? 'datetimepicker' : '');
?>
