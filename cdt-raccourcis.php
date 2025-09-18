<?php
// Sécurité : ne doit pas être exécuté s'il n'est pas lancé par un script autorisé
// Compatibilité : si lancé directement, on charge cdt.php
if ( !defined('OK') )  {
  $_REQUEST['raccourcis'] = 1;
  include('cdt.php');
  exit(); // Inutile
}

// Page de modification des types de séances du cahier de texte
// réservée aux professeurs associés à la matière
// Autorisation obligatoirement égale à 5
// Variables $matiere, $mid et $cle déjà réglées

// Récupération des types de séances
$resultat = $mysqli->query("SELECT id, titre, deb_fin_pour FROM `cdt-types` WHERE matiere = $mid ORDER BY ordre");
$select_seances = '';
$seances = array();
if ( $resultat->num_rows )  {
  while ( $r = $resultat->fetch_assoc() )  {
    $select_seances .= "<option value=\"${r['id']}\">${r['titre']}</option>";
    $seances[$r['id']] = $r['deb_fin_pour'];
  }
  $resultat->free();
}
// Select sur les jours de la semaine et pour les demigroupes
$select_jours = '<option value="1">Lundi précédent</option><option value="2">Mardi précédent</option><option value="3">Mercredi précédent</option><option value="4">Jeudi précédent</option><option value="5">Vendredi précédent</option><option value="6">Samedi précédent</option><option value="8">Lundi suivant</option><option value="9">Mardi suivant</option><option value="10">Mercredi suivant</option><option value="11">Jeudi suivant</option><option value="12">Vendredi suivant</option><option value="13">Samedi suivant</option>';
$select_dg = '<option value="0">Classe entière</option><option value="1">Demi-groupe</option>';

//////////////
//// HTML ////
//////////////
debut($mysqli,"Cahier de texte en ${matiere['nom']} - Modifier les boutons de raccourcis",$message,5,"cdt?$cle&raccourcis",array('action'=>'cdt-raccourcis','matiere'=>$mid,'css'=>'datetimepicker'));
echo <<<FIN

  <div id="icones" data-action="page">
    <a class="icon-ajoute formulaire" title="Ajouter un nouveau raccourci de séance"></a>
    <a class="icon-aide" title="Aide pour les modifications des raccourcis de séance"></a>
  </div>

  <article>
    <input onclick="location.href='cdt?$cle'" type="button" class="ligne" value="Revenir au cahier de texte">
    <input onclick="location.href='cdt?$cle&amp;seances'" type="button" class="ligne" value="Modifier les types de séances">
  </article>

FIN;

// Récupération
$resultat = $mysqli->query("SELECT id, ordre, nom, jour, type, demigroupe, TIME_FORMAT(h_debut,'%kh%i') AS h_debut, TIME_FORMAT(h_fin,'%kh%i') AS h_fin, template
                            FROM `cdt-seances` WHERE matiere = $mid ORDER BY ordre");
$mysqli->close();
if ( $max = $resultat->num_rows )  {
  while ( $r = $resultat->fetch_assoc() )  {
    $id = $r['id'];
    $monte = ( $r['ordre'] == 1 ) ? ' style="display:none;"' : '';
    $descend = ( $r['ordre'] == $max ) ? ' style="display:none;"' : '';
    $sel_jours = str_replace("\"${r['jour']}\"","\"${r['jour']}\" selected",$select_jours);
    $sel_seances = str_replace("\"${r['type']}\"","\"${r['type']}\" selected",$select_seances);
    $sel_dg = str_replace("\"${r['demigroupe']}\"","\"${r['demigroupe']}\" selected",$select_dg);
    
    echo <<<FIN

  <article data-id="$id">
    <a class="icon-aide" data-id="raccourci" title="Aide pour l'édition de ce raccourci de séance"></a>
    <a class="icon-ok" title="Valider les modifications"></a>
    <a class="icon-monte"$monte title="Déplacer ce type de séance vers le haut"></a>
    <a class="icon-descend"$descend title="Déplacer ce type de séance vers le bas"></a>
    <a class="icon-supprime" title="Supprimer ce type de séance"></a>
    <form class="cdt-raccourcis">
      <h3 class="edition">${r['nom']}</h3>
      <p class="ligne"><label for="nom0">Nom&nbsp;: </label><input type="text" id="nom0" name="nom" value="${r['nom']}" size="50"></p>
      <p class="ligne"><label for="type0">Séance&nbsp;:</label>
        <select id="type0" name="type">$sel_seances</select>
      </p>
      <p class="ligne"><label for="jour0">Jour&nbsp;:</label>
        <select id="jour0" name="jour">$sel_jours</select>
      </p>
      <p class="ligne"><label for="h_debut$id">Heure de début&nbsp;: </label><input type="text" id="h_debut$id" name="h_debut" value="${r['h_debut']}" size="5"></p>
      <p class="ligne"><label for="h_fin$id">Heure de fin&nbsp;: </label><input type="text" id="h_fin$id" name="h_fin" value="${r['h_fin']}" size="5"></p>
      <p class="ligne"><label for="demigroupe$id">Séance en demi-groupe&nbsp;: </label>
        <select id="demigroupe$id" name="demigroupe">$sel_dg</select>
      </p>
      <p class="ligne"><label for="template">Modèle de texte&nbsp;:</label></p>
      <textarea name="template" rows="6" cols="100" placeholder="Texte apparaissant dans le nouvel élément au clic sur le raccourci (non obligatoire)">${r['template']}</textarea>
    </form>
  </article>

FIN;
  }
  $resultat->free();
}
else
  echo "\n  <article>\n    <h2>Aucun raccourci de séances n'existe encore pour cette matière.</h2>\n    <p>Cliquez sur le bouton <span class=\"icon-ajoute\"></span> en haut de cette page pour en ajouter.</p>\n  </article>\n";
 
// Aide et formulaire d'ajout
?>

  <form id="form-ajoute" data-action="ajout-cdt-raccourci">
    <h3 class="edition">Ajouter un nouveau raccourci de séances</h3>
    <div>
      <p class="ligne"><label for="nom">Nom&nbsp;: </label><input type="text" name="nom" value="" size="50"></p>
      <p class="ligne"><label for="type">Séance&nbsp;:</label>
        <select name="type"><?php echo $select_seances; ?></select>
      </p>
      <p class="ligne"><label for="jour">Jour&nbsp;:</label>
        <select name="jour"><?php echo $select_jours; ?></select>
      </p>
      <p class="ligne"><label for="h_debut">Heure de début&nbsp;: </label><input type="text" name="h_debut" value="" size="5"></p>
      <p class="ligne"><label for="h_fin">Heure de fin&nbsp;: </label><input type="text" name="h_fin" value="" size="5"></p>
      <p class="ligne"><label for="demigroupe">Séance en demi-groupe&nbsp;: </label>
        <select name="demigroupe"><?php echo $select_dg; ?></select>
      </p>
      <p class="ligne"><label for="template">Modèle de texte&nbsp;:</label></p>
      <textarea name="template" rows="6" cols="100" placeholder="Texte apparaissant dans le nouvel élément au clic sur le raccourci (non obligatoire)"></textarea>
    </div>
  </form>
  
  <script type="text/javascript">
    seances = <?php echo json_encode($seances); ?>;
  </script>

  <div id="aide-page">
    <h3>Aide et explications</h3>
    <p>Il est possible ici d'ajouter ou de modifier des <em>raccourcis</em> pour le cahier de texte en <?php echo $matiere['nom']; ?>. Ces raccourcis formeront un menu déroulant disponible lors de l'édition des horaires d'un nouvel élément du cahier de texte ou leur modification.</p>
    <p>Ces raccourcis permettent de pré-remplir le nouvel élément du cahier de texte. Cela permet donc d'aller plus vite lors du remplissage du cahier de texte. Les raccourcis n'apparaissent qu'aux professeurs associés à la matière, non devant les élèves ou autres visiteurs.</p>
    <p>On peut par exemple disposer d'un raccourci &laquo;&nbsp;Cours du lundi&nbsp;&raquo; qui permettra de régler automatiquement le type de séance à Cours, le jour de la semaine au lundi de la semaine en cours, les heures de début et fin à 8h et 10h.</p>
    <p>Le <em>nom</em> est ce qui sera affiché dans le menu d'accès aux raccourcis, visible lors de l'édition d'un élément du cahier de texte. On peut mettre ce que l'on veut.</p>
    <p>La <em>séance</em> est le type de séance qui sera automatiquement sélectionné. Les types de séances sont modifiables, indépendamment pour chaque matière, sur la page de <a href="cdt?<?php echo $cle; ?>&seances">modification des types de séances</a>.</p>
    <p>Le <em>modèle de texte</em> correspond à un texte qui sera automatiquement ajouté lors de la sélection du raccourci et pourra bien sûr être modifié avant la validation de l'élément du cahier de texte.</p>
    <p>Pour ajouter un raccourci, il faut cliquer sur le bouton <span class="icon-ajoute"></span> en haut à droite de la page.</p>
    <p>Les raccourcis existants sont directement modifiables. Les modifications sont prises en compte après validation avec le bouton <span class="icon-ok"></span>.</p>
  </div>

  <div id="aide-cdt-raccourcis">
    <h3>Aide et explications</h3>
    <p>Le <em>nom</em> est ce qui sera affiché dans le menu d'accès aux raccourcis, visible lors de l'édition d'un élément du cahier de texte. On peut mettre ce que l'on veut.</p>
    <p>La <em>séance</em> est le type de séance qui sera automatiquement sélectionné. Les types de séances sont modifiables, indépendamment pour chaque matière, à la <a href="cdt-seances?<?php echo $cle; ?>">gestion de types de séances</a>.</p>
    <p>Le <em>modèle de texte</em> correspond à un texte qui sera automatiquement ajouté lors de la sélection du raccourci et pourra bien sûr être modifié avant la validation de l'élément du cahier de texte.</p>
    <p>Une fois les modifications faites, il faut les valider en cliquant sur le bouton <span class="icon-ok"></span>.</p>
    <p>D'autres modifications sont possibles à l'aide des boutons disponibles pour chaque raccourci de séance&nbsp;:</p>
    <ul>
      <li><span class="icon-supprime"></span>&nbsp;: supprimer le raccourci de séance (une confirmation sera demandée)</li>
      <li><span class="icon-monte"></span>&nbsp;: remonter le raccourci de séance d'un cran</li>
      <li><span class="icon-descend"></span>&nbsp;: descendre le raccourci de séance d'un cran</li>
    </ul>
    <p>Supprimer un raccourci de séance n'a strictement aucun impact sur les éléments du cahier de texte.</p>
  </div>

  <div id="aide-ajoute">
    <h3>Aide et explications</h3>
    <p>Ce formulaire permet de créer un nouveau raccourci de séance. Il sera validé par un clic sur <span class="icon-ok"></span>, et abandonné (donc supprimé) par un clic sur <span class="icon-ferme"></span>.</p>
    <p>Chaque raccourci de séance ne concerne qu'une matière à la fois.</p>
    <p>Le <em>nom</em> est ce qui sera affiché dans le menu d'accès aux raccourcis, visible lors de l'édition d'un élément du cahier de texte. On peut mettre ce que l'on veut.</p>
    <p>La <em>séance</em> est le type de séance qui sera automatiquement sélectionné. Les types de séances sont modifiables, indépendamment pour chaque matière, sur la page de <a href="cdt-seances?<?php echo $cle; ?>">modification des types de séances</a>.</p>
    <p>Le <em>modèle de texte</em> correspond à un texte qui sera automatiquement ajouté lors de la sélection du raccourci et pourra bien sûr être modifié avant la validation de l'élément du cahier de texte.</p>
  </div>
  
<?php
fin(true,true,'datetimepicker');
?>
