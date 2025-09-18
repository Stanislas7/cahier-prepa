<?php
// Sécurité
define('OK',1);
// Configuration
include('config.php');
// Fonctions
include('fonctions.php');

//////////////////
// Autorisation //
//////////////////

// Accès aux professeurs connectés uniquement. Redirection pour les autres.
if ( $autorisation < 5 )  {
  header("Location: https://$domaine$chemin");
  exit();
}
$mysqli = connectsql();
// Si connexion light : on doit répéter son mot de passe pour aller plus loin
// login.php contient fin()
if ( $_SESSION['light'] )  {
  $titre = 'Types d\'événements de l\'agenda';
  $actuel = 'agenda';
  include('login.php');
}

//////////////
//// HTML ////
//////////////
debut($mysqli,"Agenda - Types d'événements",$message,5,'agenda',array('action'=>'agenda-types','css'=>'colpick'));
echo <<<FIN

  <div id="icones" data-action="page">
    <a class="icon-ajoute formulaire" title="Ajouter un nouveau type d'événements"></a>
    <a class="icon-annule" onclick="history.back()" title="Retour à l'agenda"></a>
    <a class="icon-aide" title="Aide pour les modifications des types d'événements"></a>
  </div>

FIN;

// Récupération des préférences globales pour la page d'accueil
$resultat = $mysqli->query('SELECT GROUP_CONCAT(val ORDER BY nom) FROM prefs WHERE nom LIKE \'agenda%max\'');
list($datemax,$nbmax) = explode(',',$resultat->fetch_row()[0]);
$resultat->free();

// Récupération des types
$resultat = $mysqli->query('SELECT t.id, ordre, nom, cle, couleur, index_nbmax, index_datemax, COUNT(a.id) AS nb FROM `agenda-types` AS t LEFT JOIN agenda AS a ON type = t.id GROUP BY t.id ORDER BY t.ordre');
$mysqli->close();
$max = $resultat->num_rows;
while ( $r = $resultat->fetch_assoc() )  {
  $id = $r['id'];
  $s = ( $r['nb'] > 1 ) ? 's' : '';
  $suppr = ( $r['nb'] ) ? '' : "\n    <a class=\"icon-supprime\" title=\"Supprimer ce type d'événements\"></a>";
  $monte = ( $r['ordre'] == 1 ) ? ' style="display:none;"' : '';
  $descend = ( $r['ordre'] == $max ) ? ' style="display:none;"' : '';
  echo <<<FIN

  <article data-id="$id">
    <a class="icon-ok" title="Valider les modifications"></a>
    <a class="icon-aide" title="Aide pour l'édition de ce type d'événements"></a>
    <a class="icon-monte"$monte title="Déplacer ce type d'événements vers le haut"></a>
    <a class="icon-descend"$descend title="Déplacer ce type d'événements vers le bas"></a>$suppr
    <h3 class="edition">${r['nom']}</h3>
    <form>
      <p>Ce type d'événements correspond à ${r['nb']} événement$s dans l'agenda.</p>
      <p class="ligne"><label for="nom$id">Nom&nbsp;: </label><input type="text" id="nom$id" name="nom" value="${r['nom']}" size="50"></p>
      <p class="ligne"><label for="cle$id">Clé&nbsp;: </label><input type="text" id="cle$id" name="cle" value="${r['cle']}" size="50"></p>
      <p class="ligne"><label for="couleur$id">Couleur&nbsp;: </label><input type="text" id="couleur$id" name="couleur" value="${r['couleur']}" size="6"></p>
      <p class="ligne"><label for="nbmax$id">Nombre maximal d'événements affichés&nbsp;: </label><input type="number" id="nbmax$id" name="nbmax" value="${r['index_nbmax']}" max="${r['index_nbmax']}" size="3"></p>
      <p class="ligne"><label for="datemax$id">Nombre maximal de jours affichés&nbsp;: </label><input type="number" id="datemax$id" name="datemax" value="${r['index_datemax']}" max="${r['index_datemax']}" size="3"></p>
      <p>Les réglages globaux de l'agenda précisent qu'il ne peut y avoir plus de $nbmax événements affichés tous types confondus, et au maximum sur les $datemax prochains jours. Les deux réglages 
      particuliers ci-dessus ne peuvent être supérieurs à ces valeurs.</p>
    </form>
  </article>

FIN;
}
$resultat->free();

// Aide et formulaire d'ajout
?>

  <form id="form-ajoute" data-action="ajout-agenda-types">
    <h3 class="edition">Ajouter un nouveau type d'événements</h3>
    <div>
      <input type="text" class="ligne" name="nom" value="" size="50" placeholder="Nom pour l'affichage (Commence par majuscule, singulier)">
      <input type="text" class="ligne" name="cle" value="" size="50" placeholder="Clé pour les adresses web (Un seul mot, minuscules ou sigle, singulier)">
      <input type="text" class="ligne" name="couleur" value="" size="6" placeholder="Couleur des événements (code RRGGBB)">
      <p class="ligne"><label for="nbmax">Nombre maximal d'événements affichés&nbsp;: </label><input type="number" name="nbmax" value="<?php echo $nbmax; ?>" max="<?php echo $nbmax; ?>" size="3"></p>
      <p class="ligne"><label for="datemax">Nombre maximal de jours affichés&nbsp;: </label><input type="number" name="datemax" value="<?php echo $datemax; ?>" max="<?php echo $datemax; ?>" size="3"></p>
      <p>Les réglages globaux de l'agenda précisent qu'il ne peut y avoir plus de <?php echo $nbmax; ?> événements affichés tous types confondus, et au maximum sur les <?php echo $datemax; ?> prochains jours. Les deux réglages 
      particuliers ci-dessus ne peuvent être supérieurs à ces valeurs.</p>
    </div>
  </form>

  <div id="aide-page">
    <h3>Aide et explications</h3>
    <p>Il est possible ici d'ajouter ou de modifier les types d'événements de l'agenda. Ces modifications sont communes à toutes les matières.</p>
    <p>Le <em>nom</em> sera affiché au début de chaque événement, sur l'agenda et sur la page des <span class="icon-recent"></span>&nbsp;derniers contenus. Il doit s'agir d'un nom singulier et commençant par une majuscule. Il peut être relativement long. Par exemple&nbsp;: «&nbsp;Annulation de cours&nbsp;», «&nbsp;Interrogation de cours&nbsp;» (si vous souhaitez les annoncer :-) )</p>
    <p>La <em>clé</em> sera affichée dans le menu déroulant de recherche, précédé de «&nbsp;les&nbsp», ainsi que dans l'adresse des pages qui affichent ce type d'événements&nbsp;: il faut donc que ce soit un pluriel, pas trop long, en un mot, sans majucule au début (sauf s'il le faut). Par exemple, «&nbsp;annulations&nbsp;», «&nbsp;interros&nbsp;».</p>
    <p>La <em>couleur</em> est celle qui sera affichée pour tous les événements du type concerné, dans le calendrier. Elle est codée sous la forme <code>RRGGBB</code>, un sélecteur de couleur apparaît au clic sur la case colorée.</p>
    <p>Le <em>nombre maximal d'événements affichés</em> et le <em>nombre maximal de jours affichés</em> forment un réglage spécifique au type permettant de régler finement l'affichage des événements proches sur la page d'accueil du site (voir ci-dessous).</p>
    <h4>Suppression et gestion de l'ordre d'affichage</h4>
    <p>Il est possible de supprimer un type d'événements en cliquant sur le bouton <span class="icon-supprime"></span> (une confirmation sera demandée).</p>
    <p>Seuls les types ne correspondant à aucun événement peuvent être supprimés. Le nombre d'événements correspondant à un type est indiqué en début de description.</p>
    <p>Tous les types d'événements peuvent être déplacés les uns par rapport aux autres, à l'aide des boutons <span class="icon-monte"></span> et <span class="icon-descend"></span>. Cela modifie leur ordre d'affichage dans les menus de sélection, pour la création et la modification d'événements.</p>
    <h4>Réglage de la visibilité des événements sur la page d'accueil</h4>
    <p>Les prochains événements apparaissent automatiquement sur la page d'accueil, en fonction de plusieurs critères&nbsp;:</p>
    <ul>
      <li>un <em>nombre maximal d'événements affichés</em> global, modifiable par les administrateurs sur la page de l'agenda et des réglages du site.</li>
      <li>un <em>nombre maximal de jours affichés</em> global, modifiable au même endroit, qui correspond à la durée maximale sur laquelle les événements sont récupérés pour être affichés.</li>
      <li>un <em>nombre maximal d'événements affichés</em> spécifique à chaque type d'événements, modifiable ici.</li>
      <li>un <em>nombre maximal de jours affichés</em> spécifique à chaque type d'événements, modifiable ici également.</li>
      <li>une propriété <em>Affichable sur la page d'accueil</em> spécifique à chaque événement, ajustable à l'ajout ou modifiable ultérieurement.</li>
    </ul>
    <p>Les <em>nombres maximums</em> spécifiques ne peuvent logiquement pas être supérieurs aux valeurs globales et sont ajustés à la baisse si besoin. On peut par exemple, pour le type «&nbsp;Devoirs surveillés&nbsp;», spécifier l'affichage au maximum d'un seul événement.</p>
  </div>

  <div id="aide-agenda-types">
    <h3>Aide et explications</h3>
    <p>Le <em>nom</em> sera affiché au début de chaque événement, sur l'agenda et sur la page des <span class="icon-recent"></span>&nbsp;derniers contenus. Il doit s'agir d'un nom singulier et commençant par une majuscule. Il peut être relativement long. Par exemple&nbsp;: «&nbsp;Annulation de cours&nbsp;», «&nbsp;Interrogation de cours&nbsp;» (si vous souhaitez les annoncer :-) )</p>
    <p>La <em>clé</em> sera affichée dans le menu déroulant de recherche, précédé de «&nbsp;les&nbsp», ainsi que dans l'adresse des pages qui affichent ce type d'événements&nbsp;: il faut donc que ce soit un pluriel, pas trop long, en un mot, sans majucule au début (sauf s'il le faut). Par exemple, «&nbsp;annulations&nbsp;», «&nbsp;interros&nbsp;».</p>
    <p>La <em>couleur</em> est celle qui sera affiché pour tous les événement du type concerné, dans le calendrier. Elle est codée sous la forme <code>RRGGBB</code>, un sélecteur de couleur apparaît au clic sur la case colorée.</p>
    <p>Le <em>nombre maximal d'événements affichés</em> et le <em>nombre maximal de jours affichés</em> forment un réglage spécifique au type permettant de régler finement l'affichage des événements proches sur la page d'accueil du site (voir ci-dessous).</p>
    <p>Une fois les modifications faites, il faut les valider en cliquant sur le bouton <span class="icon-ok"></span>.</p>
    <p>D'autres modifications sont possibles à l'aide des boutons disponibles pour chaque type d'événements&nbsp;:</p>
    <ul>
      <li><span class="icon-supprime"></span>&nbsp;: supprimer le type d'événements (une confirmation sera demandée)</li>
      <li><span class="icon-monte"></span>&nbsp;: remonter le type d'événements d'un cran</li>
      <li><span class="icon-descend"></span>&nbsp;: descendre le type d'événements d'un cran</li>
    </ul>
    <p>Seuls les types ne correspondant à aucun événement peuvent être supprimés. Le nombre d'événements correspondant à un type est indiqué en début de description.</p>
    <h4>Réglage de la visibilité des événements sur la page d'accueil</h4>
    <p>Les prochains événements apparaissent automatiquement sur la page d'accueil, en fonction de plusieurs critères&nbsp;:</p>
    <ul>
      <li>un <em>nombre maximal d'événements affichés</em> global, modifiable par les administrateurs sur la page de l'agenda et des réglages du site.</li>
      <li>un <em>nombre maximal de jours affichés</em> global, modifiable au même endroit, qui correspond à la durée maximale sur laquelle les événements sont récupérés pour être affichés.</li>
      <li>un <em>nombre maximal d'événements affichés</em> spécifique à chaque type d'événements, modifiable ici.</li>
      <li>un <em>nombre maximal de jours affichés</em> spécifique à chaque type d'événements, modifiable ici également.</li>
      <li>une propriété <em>Affichable sur la page d'accueil</em> spécifique à chaque événement, ajustable à l'ajout ou modifiable ultérieurement.</li>
    </ul>
    <p>Les <em>nombres maximums</em> spécifiques ne peuvent logiquement pas être supérieurs aux valeurs globales et sont ajustés à la baisse si besoin. On peut par exemple, pour le type «&nbsp;Devoirs surveillés&nbsp;», spécifier l'affichage au maximum d'un seul événement.</p>
  </div>

  <div id="aide-ajoute">
    <h3>Aide et explications</h3>
    <p>Ce formulaire permet de créer un nouveau type d'événements. Il sera validé par un clic sur <span class="icon-ok"></span>, et abandonné (donc supprimé) par un clic sur <span class="icon-ferme"></span>.</p>
    <p>Le <em>nom</em> sera affiché au début de chaque événement, sur l'agenda et sur la page des <span class="icon-recent"></span>&nbsp;derniers contenus. Il doit s'agir d'un nom singulier et commençant par une majuscule. Il peut être relativement long. Par exemple&nbsp;: «&nbsp;Annulation de cours&nbsp;», «&nbsp;Interrogation de cours&nbsp;» (si vous souhaitez les annoncer :-) )</p>
    <p>La <em>clé</em> sera affichée dans le menu déroulant de recherche, précédé de «&nbsp;les&nbsp», ainsi que dans l'adresse des pages qui affichent ce type d'événements&nbsp;: il faut donc que ce soit un pluriel, pas trop long, en un mot, sans majucule au début (sauf s'il le faut). Par exemple, «&nbsp;annulations&nbsp;», «&nbsp;interros&nbsp;».</p>
    <p>La <em>couleur</em> est celle qui sera affiché pour tous les événement du type concerné, dans le calendrier. Elle est codée sous la forme <code>RRGGBB</code>, un sélecteur de couleur apparaît au clic sur la case colorée.</p>
    <p>Le <em>nombre maximal d'événements affichés</em> et le <em>nombre maximal de jours affichés</em> forment un réglage spécifique au type permettant de régler finement l'affichage des événements proches sur la page d'accueil du site (voir ci-dessous).</p>
    <h4>Réglage de la visibilité des événements sur la page d'accueil</h4>
    <p>Les prochains événements apparaissent automatiquement sur la page d'accueil, en fonction de plusieurs critères&nbsp;:</p>
    <ul>
      <li>un <em>nombre maximal d'événements affichés</em> global, modifiable par les administrateurs sur la page de l'agenda et des réglages du site.</li>
      <li>un <em>nombre maximal de jours affichés</em> global, modifiable au même endroit, qui correspond à la durée maximale sur laquelle les événements sont récupérés pour être affichés.</li>
      <li>un <em>nombre maximal d'événements affichés</em> spécifique à chaque type d'événements, modifiable ici.</li>
      <li>un <em>nombre maximal de jours affichés</em> spécifique à chaque type d'événements, modifiable ici également.</li>
      <li>une propriété <em>Affichable sur la page d'accueil</em> spécifique à chaque événement, ajustable à l'ajout ou modifiable ultérieurement.</li>
    </ul>
    <p>Les <em>nombres maximums</em> spécifiques ne peuvent logiquement pas être supérieurs aux valeurs globales et sont ajustés à la baisse si besoin. On peut par exemple, pour le type «&nbsp;Devoirs surveillés&nbsp;», spécifier l'affichage au maximum d'un seul événement.</p>
  </div>
  
<?php
fin(true,false,'colpick');
?>
