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

// Accès aux administrateurs, profs, et lycée. Redirection pour les autres.
// (mais édition possible pour les profs uniquement sur ses propres matières,
// sur toutes pour les admins, et pas pour les comptes lycée.
if ( $autorisation && ( $autorisation < 4 ) && !$_SESSION['admin'] )  {
  header("Location: https://$domaine$chemin");
  exit();
}
$mysqli = connectsql();
// Si non connecté, demande de connexion
// Si connexion light : on doit répéter son mot de passe pour aller plus loin
// login.php contient fin()
if ( !$autorisation || $_SESSION['light'] )  {
  $titre = 'Modification des matières';
  $actuel = 'matieres';
  include('login.php');
}
$edition = $_SESSION['admin'];

//////////////
//// HTML ////
//////////////
debut($mysqli,'Modification des matières',$message,$autorisation,'matieres',array('action'=>'matieres','matiere'=>-1));
$icone = ( $edition ) ? "    <a class=\"icon-ajoute formulaire\" title=\"Ajouter une nouvelle matière\"></a>\n" : '';
echo <<<FIN

  <div id="icones" data-action="page">$icone
    <a class="icon-aide" title="Aide pour les modifications des matières"></a>
  </div>

FIN;

if ( $edition )
  $readonly = '0';
elseif( $autorisation == 4 )
  $readonly = '1';
else
  $readonly = "NOT FIND_IN_SET(m.id,'${_SESSION['matieres']}')";

// Récupération
$resultat = $mysqli->query("SELECT $readonly AS readonly, m.id, ordre, cle, m.nom, progcolles, cdt, docs, notescolles, transferts, dureecolles, heurescolles,
                            progcolles_protection, cdt_protection, docs_protection, COUNT(u.id) AS nbeleves
                            FROM matieres AS m LEFT JOIN utilisateurs AS u ON FIND_IN_SET(m.id,u.matieres) AND autorisation = 2 AND mdp > '0' GROUP BY m.id ORDER BY ordre");
$max = $resultat->num_rows;
while ( $r = $resultat->fetch_assoc() )  {
  $id = $r['id'];
  $boutons = '';
  $ne = $r['nbeleves'];
  $associationeleves = 'Cette matière '.( $ne > 0 ? "concerne $ne élève".($ne > 1 ? 's' : '') : 'ne concerne aucun élève');
  if ( $r['readonly'] )  {
    $readonly = ' disabled';
    $icones = '';
  }
  else  {
    $readonly = '';
    $monte = ( $r['ordre'] == 1 ) ? ' style="display:none;"' : '';
    $descend = ( $r['ordre'] == $max ) ? ' style="display:none;"' : '';
    $suppr = ( $max > 1 ) ? "\n    <a class=\"icon-supprime\" title=\"Supprimer cette matière\"></a>" : '';
    $icones = "
    <a class=\"icon-aide\" title=\"Aide pour l'édition de cette matière\"></a>
    <a class=\"icon-ok\" title=\"Valider les modifications\"></a>
    <a class=\"icon-descend\"$descend title=\"Déplacer cette matière vers le bas\"></a>
    <a class=\"icon-monte\"$monte title=\"Déplacer cette matière vers le haut\"></a>$suppr";
    if ( $r['progcolles'] == 1 )  $boutons .= "\n    <input type=\"button\" class=\"ligne supprmultiple\" data-type=\"progcolles\" value=\"Supprimer tous les programmes de colles\">";
    if ( $r['cdt'] == 1 )         $boutons .= "\n    <input type=\"button\" class=\"ligne supprmultiple\" data-type=\"cdt\" value=\"Supprimer tout le cahier de texte\">";
    if ( $r['docs'] == 1 )        $boutons .= "\n    <input type=\"button\" class=\"ligne supprmultiple\" data-type=\"docs\" value=\"Supprimer tous les répertoires et documents\">";
    if ( $r['transferts'] == 1 )  $boutons .= "\n    <input type=\"button\" class=\"ligne supprmultiple\" data-type=\"transferts\" value=\"Supprimer tous les transferts de documents personnels\">";
    if ( $r['notescolles'] == 1 ) $boutons .= "\n    <input type=\"button\" class=\"ligne supprmultiple\" data-type=\"notescolles\" value=\"Supprimer toutes les notes\">";
    $associationeleves .= '. Ceci est modifiable sur la page de <a href="utilisateurs-matieres">gestion des associations utilisateurs-matières</a>';
  }
  if ( $r['progcolles'] == 2 )  $r['progcolles_protection'] = 33;
  if ( $r['cdt'] == 2 )         $r['cdt_protection'] = 33;
  if ( $r['docs'] == 2 )        $r['docs_protection'] = 33;
  $transferts = str_replace( "\"${r['transferts']}\"", "\"${r['transferts']}\" selected", "\n          <option value=\"1\">Fonction activée</option>\n          <option value=\"2\">Fonction désactivée</option>");
  $notescolles = str_replace( "\"${r['notescolles']}\"", "\"${r['notescolles']}\" selected", "\n          <option value=\"1\">Fonction activée</option>\n          <option value=\"2\">Fonction désactivée</option>");
  $heurescolles = str_replace( "\"${r['heurescolles']}\"", "\"${r['heurescolles']}\" selected", "\n          <option value=\"0\">Décompte du temps à l'élève (40 min = 40 min)</option>\n          <option value=\"1\">Arrondi à l'heure pleine (40 min = 1 h)</option>");
  echo <<<FIN

  <article data-id="$id">$icones
    <form>
      <h3 class="edition">${r['nom']}</h3>
      <p class="ligne"><label for="nom$id">Nom complet&nbsp;: </label><input$readonly type="text" id="nom$id" name="nom" placeholder="Commence par une majuscule : Mathématiques, Physique..." value="${r['nom']}" size="50"></p>
      <p class="ligne"><label for="cle$id">Clé dans l'adresse&nbsp;: </label><input$readonly type="text" id="cle$id" name="cle" placeholder="Diminutif en minuscules : maths, phys..." value="${r['cle']}" size="30"></p>
      <p class="ligne" data-protection="${r['progcolles_protection']}"><label>Accès aux programmes de colles&nbsp;: </label><select$readonly name="progcolles_protection[]" multiple></select></p>
      <p class="ligne" data-protection="${r['cdt_protection']}"><label>Accès au cahier de texte&nbsp;: </label><select$readonly name="cdt_protection[]" multiple></select></p>
      <p class="ligne" data-protection="${r['docs_protection']}"><label>Accès aux documents&nbsp;: </label><select$readonly name="docs_protection[]" multiple></select></p>
      <p class="ligne"><label>Transferts de documents&nbsp;: </label>
        <select$readonly name="transferts">$transferts
        </select>
      </p>
      <p class="ligne"><label>Notes de colles&nbsp;: </label>
        <select$readonly name="notescolles">$notescolles
        </select>
      </p>
      <p class="ligne"><label for="dureecolles$id">Durée des colles en minutes par élève&nbsp;: </label><input$readonly type="text" id="dureecolles$id" name="dureecolles" placeholder="Valeur par défaut. Typiquement 20 ou 30." value="${r['dureecolles']}" size="3"></p>
      <p class="ligne"><label>Heures de colles insécables&nbsp;: </label>
        <select$readonly name="heurescolles">$heurescolles
        </select>
      </p>
      <p>$associationeleves.</p>
    </form>$boutons
  </article>

FIN;
}
$resultat->free();


// Aide et formulaire d'ajout
// ajout : seulement admin
// modif : admin = toutes matières, prof = seulement ses matières
if ( $edition )  { 
?>

  <form id="form-ajoute" data-action="ajout-matiere">
    <h3 class="edition">Ajouter une nouvelle matière</h3>
    <p class="ligne"><label for="nom">Nom complet&nbsp;: </label><input type="text" id="nom" name="nom" placeholder="Commence par une majuscule : Mathématiques, Physique..." value="" size="50"></p>
    <p class="ligne"><label for="cle">Clé dans l'adresse&nbsp;: </label><input type="text" id="cle" name="cle" placeholder="Diminutif en minuscules : maths, phys..." value="" size="30"></p>
    <p class="ligne"><label for="progcolles_protection">Accès aux programmes de colles&nbsp;: </label><select name="progcolles_protection[]" multiple></select></p>
    <p class="ligne"><label for="cdt_protection">Accès au cahier de texte&nbsp;: </label><select name="cdt_protection[]" multiple></select></p>
    <p class="ligne"><label for="docs_protection">Accès aux documents&nbsp;: </label><select name="docs_protection[]" multiple></select></p>
    <p class="ligne"><label for="transferts">Accès aux transferts de documents&nbsp;: </label>
      <select name="transferts">
        <option value="0">Fonction activée</option>
        <option value="1">Fonction désactivée</option>
      </select>
    </p>
    <p class="ligne"><label for="notescolles">Notes de colles&nbsp;: </label>
      <select name="notescolles">
        <option value="0">Fonction activée</option>
        <option value="1">Fonction désactivée</option>
      </select>
    </p>
    <p class="ligne"><label for="dureecolles">Durée des colles en minutes par élève&nbsp;: </label><input type="text" id="dureecolles" name="dureecolles" value="" size="3" placeholder="Durée des colles en minutes par élève. Typiquement 20 ou 30."></p>
    <p class="ligne"><label>Heures de colles insécables&nbsp;: </label>
      <select name="heurescolles">
          <option value="0">Décompte à l'élève (40 min = 40 min)</option>
          <option value="1">Arrondi à l'heure pleine (40 min = 1 h)</option>
      </select>
    </p>
  </form>

  <div id="aide-page">
    <h3>Aide et explications</h3>
    <p>Il est possible ici d'ajouter ou de modifier les matières enregistrées.</p>
    <p>Seules les matières qui proposent un contenu (cahier de texte, programmes de colles, documents, notes, transferts de documents) sont visibles dans le menu général. Cela est automatiquement mis à jour à chaque ajout/suppression d'une ressource.</p>
    <p>L'ordre d'apparition des matières dans le menu général, notamment pour les élèves, est modifiable grâce aux boutons <span class="icon-monte"></span> et <span class="icon-descend"></span>.</p>
    <p>Vous disposez des droits d'administration, vous pouvez donc modifier toutes les matières. Les professeurs non administrateurs ne peuvent modifier que les matières auxquelles ils sont associés. Ces associations sont modifiables à la page de <a href="utilisateurs-matieres">gestion utilisateurs-matières</a>. Les utilisateurs non professeurs et non administrateurs n'ont pas accès à cette page. Les administrateurs sont listés sur la page de <a href="utilisateurs">gestion des utilisateurs</a>.</p>
    <h4>Propriétés de chaque matière</h4>
    <p>Pour chaque matière, vous pouvez modifier&nbsp;:</p>
    <ul>
      <li>le <em>nom complet</em> qui s'affiche dans le menu général et dans les titres des pages propres à la matières (cahier de texte, programmes de colles, documents, notes). Par exemple, «&nbsp;Mathématiques&nbsp;», «&nbsp;Physique&nbsp;», «&nbsp;Économie, Sociologie, Histoire&nbsp;»...</li>
      <li>la <em>clé dans l'adresse</em> qui est un mot-clé utilisé uniquement dans l'adresse des pages associées à la matière. Il vaut mieux que ce soit un mot unique, court (possiblement abrégé) et sans majuscule. Par exemple, «&nbsp;maths&nbsp;», «&nbsp;phys&nbsp;», «&nbsp;esh&nbsp;»...</li>
      <li>l'activation ou non des fonctions et leur accès&nbsp;: voir ci-dessous</li>
      <li>la <em>durée des colles en minutes par élève</em>, qui permet de précalculer automatiquement la durée des colles déclarées.</li>
      <li>la propriété <em>heures de colles insécables</em>, qui permet aussi de précalculer automatiquement la durée des colles déclarées. Voir ci-dessous le détail.</li>
    </ul>
    <h4>Calcul automatique de la durée des colles</h4>
    <p>La durée des colles est automatiquement calculée lors de la saisie des notes réalisée par les colleurs. Les deux paramètres de calculs sont indépendants à chaque matière.</p>
    <p>Les colleurs ne saisissent en réalité que les notes qu'ils donnent, et le système calcule en fonction de ces deux paramètres la durée correspondante.</p>
    <p>La durée est immédiatement calculée. Modifier les paramètres de calcul ne modifie pas les durées déjà calculées, mais seulement celles qui seront déclarées dans l'avenir. Il est possible de régler 20 minutes par élève pendant l'année et 30 pendant les préparations à l'oral par exemple.</p>
    <p>Chaque durée de colle est modifiable par le professeur de la matière, par les comptes administrateurs du Cahier et par les comptes de type lycée, tant que la colle n'a pas été relevée.</p>
    <h4>Gestion des accès</h4>
    <p>L'<em>accès au programmes de colles</em>, l'<em>accès au cahier de texte</em> et l'<em>accès aux documents</em> peuvent être choisis parmi trois catégories de possibilités&nbsp;:</p>
    <ul>
      <li><em>Accès public</em>&nbsp;: ressource accessible par tout visiteur, sans identification. Les moteurs de recherche ont accès aux ressources.</li>
      <li><em>Utilisateurs identifiés</em>&nbsp;: ressource accessible uniquement par les utilisateurs identifiés à déterminer, en fonction de leur type de compte et éventuellement des matières auxquelles ils sont associés (seuls les utilisateurs du type autorisé et associés à la matière peuvent accéder à la ressource). Les associations entre utilisateurs et matières sont modifiables à la page de <a href="utilisateurs-matieres">gestion utilisateurs-matières</a>.</li>
      <li><em>Fonction désactivée</em>&nbsp;: ressource complètement indisponible pour cette matière. La fonction n'apparaît plus dans le menu, y compris pour vous. La page correspondante n'est pas disponible.</li>
    </ul>
    <p>L'<em>accès aux transferts de documents</em> est aussi modifiable. La différence avec les autres fonctions est que l'accès est automatiquement mis à jour à chaque ajout de transfert, pour permettre à tous les utilisateurs d'y accéder. Ce réglage n'est utile que si vous souhaitez supprimer l'accès aux colleurs ou aux comptes de type lycée, car l'accès aux élèves et aux professeurs associés à la matière est garanti, à moins que la fonction soit désactivée.</p>
    <p>Vous pouvez aussi activer ou désactiver les <em>notes de colles</em>.</p>
    <p>Lorsque vous désactivez une fonction, cela ne supprime jamais les éléments qui auraient été déjà saisis ou les documents envoyés, mais bloque simplement l'accès. Ce choix est donc entièrement réversible.</p>
    <p>Les liens dans le menu n'existent que lorsque du contenu est présent. Ils sont visibles de tout visiteur avant identification. Ils disparaissent après identification pour les utilisateurs n'ayant pas accès aux contenus.</p>
    <h4>Suppressions massives</h4>
    <p>Pour chaque matière, il est possible de supprimer en un seul coup les programmes de colles, le cahier de texte, les répertoires et documents, les notes de colles ou les transferts de documents personnels, en cliquant sur le bouton correspondant. Une confirmation sera demandée. Les boutons ne s'affichent pas s'il n'y a rien à supprimer.</p>
    <p>Il est possible de complètement supprimer une matière en cliquant sur le bouton <span class="icon-supprime"></span>. Une confirmation sera demandée. Cela entraîne la suppression définitive de tous les contenus associés à la matière</p>
    <h4>Associations utilisateurs-matières</h4>
    <p>Les associations utilisateurs-matières se trouvent sur une <a href="utilisateurs-matieres">page séparée</a>.</p>
  </div>

  <div id="aide-ajoute">
    <h3>Aide et explications</h3>
    <p>Ce formulaire permet de créer une nouvelle matière. Il sera validé par un clic sur <span class="icon-ok"></span>, et abandonné (donc supprimé) par un clic sur <span class="icon-ferme"></span>.</p>
    <p>Une nouvelle matière, ainsi que chaque rubrique associée, n'apparaît pas immédiatement dans le menu, mais uniquement lorsque du contenu (cahier de texte, programmes de colles, documents, notes, transferts de documents) est visible. Cela est automatiquement mis à jour à chaque ajout/suppression d'une ressource.</p>
    <p>Le <em>nom complet</em> s'affiche dans le menu général et dans les titres des pages propres à la matières (cahier de texte, programmes de colles, documents, notes). Par exemple, «&nbsp;Mathématiques&nbsp;», «&nbsp;Physique&nbsp;», «&nbsp;Économie, Sociologie, Histoire&nbsp;»...</p>
    <p>La <em>clé dans l'adresse</em> est un mot-clé utilisé uniquement dans l'adresse des pages associées à la matière. Il vaut mieux que ce soit un mot unique, court (possiblement abrégé) et sans majuscule. Par exemple, «&nbsp;maths&nbsp;», «&nbsp;phys&nbsp;», «&nbsp;esh&nbsp;»... La clé doit obligatoirement être unique (deux matières ne peuvent pas avoir la même clé).</p>
    <h4>Calcul automatique de la durée des colles</h4>
    <p>La <em>durée des colles en minutes par élève</em> et la propriété <em>heures de colles insécables</em> permettent de précalculer automatiquement la durée des colles déclarées.</li>
    <p>Les colleurs ne saisissent en réalité que les notes qu'ils donnent, et le système calcule en fonction de ces deux paramètres la durée correspondante.</p>
    <h4>Gestion des accès</h4>
    <p>L'<em>accès au programmes de colles</em>, l'<em>accès au cahier de texte</em> et l'<em>accès aux documents</em> peuvent être choisis parmi trois catégories de possibilités&nbsp;:</p>
    <ul>
      <li><em>Accès public</em>&nbsp;: ressource accessible par tout visiteur, sans identification. Les moteurs de recherche ont accès aux ressources.</li>
      <li><em>Utilisateurs identifiés</em>&nbsp;: ressource accessible uniquement par les utilisateurs identifiés à déterminer, en fonction de leur type de compte et éventuellement des matières auxquelles ils sont associés (seuls les utilisateurs du type autorisé et associés à la matière peuvent accéder à la ressource). Les associations entre utilisateurs et matières sont modifiables à la page de <a href="utilisateurs-matieres">gestion utilisateurs-matières</a>.</li>
      <li><em>Fonction désactivée</em>&nbsp;: ressource complètement indisponible pour cette matière. La fonction n'apparaît plus dans le menu, y compris pour vous. La page correspondante n'est pas disponible.</li>
    </ul>
    <p>L'<em>accès aux transferts de documents</em> est aussi modifiable. La différence avec les autres fonctions est que l'accès est automatiquement mis à jour à chaque ajout de transfert, pour permettre à tous les utilisateurs d'y accéder. Ce réglage n'est utile que si vous souhaitez supprimer l'accès aux colleurs ou aux comptes de type lycée, car l'accès aux élèves et aux professeurs associés à la matière est garanti, à moins que la fonction soit désactivée.</p>
    <p>Il est préférable de laisser désactivées les fonctions qui ne seront pas utilisées, parce que cela allège le menu des professeurs associés à la matière. Cela ne modifie pas a priori le menu des élèves, car il n'y a pas de lien dans le menu vers des pages sans ressource.</p>
    <h4>Association aux utilisateurs</h4>
    <p>La nouvelle matière sera automatiquement associée à tous les élèves. Il faudra peut-être aller ensuite associer la matière à des colleurs ou professeurs à la page de <a href="utilisateurs-matieres">gestion utilisateurs-matières</a>.</p>
  </div>
<?php 
}
// Cas des professeurs non administrateurs
else  { ?>

  <div id="aide-page">
    <h3>Aide et explications</h3>
    <p>Il est possible ici de modifier les préférences des matières associées à votre compte et de consulter les préférences des autres.</p>
    <p>Les utilisateurs disposant des droits d'administration peuvent modifier toutes les matières (y compris les vôtres).</p>
    <p>Seules les matières qui proposent un contenu (cahier de texte, programmes de colles, documents, notes, transferts de documents) sont visibles dans le menu général. Cela est automatiquement mis à jour à chaque ajout/suppression d'une ressource.</p>
    <p>L'ordre d'apparition des matières dans le menu général, notamment pour les élèves, est modifiable grâce aux boutons <span class="icon-monte"></span> et <span class="icon-descend"></span>.</p>
    <h4>Propriétés de chaque matière</h4>
    <p>Pour chaque matière associée à votre compte, vous pouvez modifier&nbsp;:</p>
    <ul>
      <li>le <em>nom complet</em> qui s'affiche dans le menu général et dans les titres des pages propres à la matières (cahier de texte, programmes de colles, documents, notes). Par exemple, «&nbsp;Mathématiques&nbsp;», «&nbsp;Physique&nbsp;», «&nbsp;Économie, Sociologie, Histoire&nbsp;»...</li>
      <li>la <em>clé dans l'adresse</em> qui est un mot-clé utilisé uniquement dans l'adresse des pages associées à la matière. Il vaut mieux que ce soit un mot unique, court (possiblement abrégé) et sans majuscule. Par exemple, «&nbsp;maths&nbsp;», «&nbsp;phys&nbsp;», «&nbsp;esh&nbsp;»... La clé doit obligatoirement être unique (deux matières ne peuvent pas avoir la même clé).</li>
      <li>l'activation ou non des fonctions et leur accès&nbsp;: voir ci-dessous</li>
      <li>la <em>durée des colles en minutes par élève</em>, qui permet de précalculer automatiquement la durée des colles déclarées.</li>
      <li>la propriété <em>heures de colles insécables</em>, qui permet aussi de précalculer automatiquement la durée des colles déclarées. Voir ci-dessous le détail.</li>
    </ul>
    <h4>Calcul automatique de la durée des colles</h4>
    <p>La durée des colles est automatiquement calculée lors de la saisie des notes réalisée par les colleurs. Les deux paramètres de calculs sont indépendants à chaque matière.</p>
    <p>Les colleurs ne saisissent en réalité que les notes qu'ils donnent, et le système calcule en fonction de ces deux paramètres la durée correspondante.</p>
    <p>La durée est immédiatement calculée. Modifier les paramètres de calcul ne modifie pas les durées déjà calculées, mais seulement celles qui seront déclarées dans l'avenir. Il est possible de régler 20 minutes par élève pendant l'année et 30 pendant les préparations à l'oral par exemple.</p>
    <p>Chaque durée de colle est modifiable par le professeur de la matière, par les comptes administrateurs du Cahier et par les comptes de type lycée, tant que la colle n'a pas été relevée.</p>
    <h4>Gestion des accès</h4>
    <p>L'<em>accès au programmes de colles</em>, l'<em>accès au cahier de texte</em> et l'<em>accès aux documents</em> peuvent être choisis parmi trois catégories de possibilités&nbsp;:</p>
    <ul>
      <li><em>Accès public</em>&nbsp;: ressource accessible par tout visiteur, sans identification. Les moteurs de recherche ont accès aux ressources.</li>
      <li><em>Utilisateurs identifiés</em>&nbsp;: ressource accessible uniquement par les utilisateurs identifiés à déterminer, en fonction de leur type de compte et éventuellement des matières auxquelles ils sont associés (seuls les utilisateurs du type autorisé et associés à la matière peuvent accéder à la ressource). Les associations entre utilisateurs et matières sont modifiables à la page de <a href="utilisateurs-matieres">gestion utilisateurs-matières</a>.</li>
      <li><em>Fonction désactivée</em>&nbsp;: ressource complètement indisponible pour cette matière. La fonction n'apparaît plus dans le menu, y compris pour vous. La page correspondante n'est pas disponible.</li>
    </ul>
    <p>L'<em>accès aux transferts de documents</em> est aussi modifiable. La différence avec les autres fonctions est que l'accès est automatiquement mis à jour à chaque ajout de transfert, pour permettre à tous les utilisateurs d'y accéder. Ce réglage n'est utile que si vous souhaitez supprimer l'accès aux colleurs ou aux comptes de type lycée, car l'accès aux élèves et aux professeurs associés à la matière est garanti, à moins que la fonction soit désactivée.</p>
    <p>Vous pouvez aussi activer ou désactiver les <em>notes de colles</em>.</p>
    <p>Lorsque vous désactivez une fonction, cela ne supprime jamais les éléments qui auraient été déjà saisis ou les documents envoyés, mais bloque simplement l'accès. Ce choix est donc entièrement réversible.</p>
    <p>Les liens dans le menu n'existent que lorsque du contenu est présent. Ils sont visibles de tout visiteur avant identification. Ils disparaissent après identification pour les utilisateurs n'ayant pas accès aux contenus.</p>
    <h4>Suppressions massives</h4>
    <p>Pour chaque matière associée à votre compte, il est possible de supprimer en un seul coup les programmes de colles, le cahier de texte, les répertoires et documents, les notes de colles ou les transferts de documents personnels, en cliquant sur le bouton correspondant. Une confirmation sera demandée. Les boutons ne s'affichent pas s'il n'y a rien à supprimer.</p>
    <p>Il est possible de complètement supprimer une matière en cliquant sur le bouton <span class="icon-supprime"></span>. Une confirmation sera demandée. Cela entraîne la suppression définitive de tous les contenus associés à la matière</p>
    <h4>Associations utilisateurs-matières</h4>
    <p>Les associations utilisateurs-matières se trouvent sur une <a href="utilisateurs-matieres">page séparée</a>. Elles ne sont modifiables que par les utilisateurs disposant des droits d'administration.</p>
<?php
  $messageprof = ( $autorisation == 5 ) ? 'Vous pouvez modifier tout ce qui correspond à votre matière.' : '';
  $resultat = $mysqli->query('SELECT GROUP_CONCAT(CONCAT(prenom," ",nom) ORDER BY nom SEPARATOR ", ") FROM utilisateurs WHERE autorisation > 10');
  $administrateurs = $resultat->fetch_row()[0];
  $resultat->free();
  echo <<< FIN
    <h4>Liste des administrateurs</h4>
    <p>Les utilisateurs disposant des droits d'administration de ce Cahier sont <strong>$administrateurs</strong>.</p>
    <p>N'hésitez pas à les contacter pour gérer les listes d'utilisateurs, le <a href="planning">planning</a>, les <a href="groupes">groupes</a> d'utilisateurs, ou ajouter une matière. $messageprof</p>
  </div>
FIN;
 } ?>

  <div id="aide-matieres">
    <h3>Aide et explications</h3>
    <p>Ce formulaire permet de modifier une matière existante. Il doit être validé par un clic sur <span class="icon-ok"></span>.</p>
    <p>L'ordre d'apparition des matières dans le menu général, notamment pour les élèves, est modifiable grâce aux boutons <span class="icon-monte"></span> et <span class="icon-descend"></span>.</p>
    <h4>Édition des propriétés</h4>
    <p>Vous pouvez modifier ici&nbsp;:</p>
    <ul>
      <li>le <em>nom complet</em> qui s'affiche dans le menu général et dans les titres des pages propres à la matières (cahier de texte, programmes de colles, documents, notes). Par exemple, «&nbsp;Mathématiques&nbsp;», «&nbsp;Physique&nbsp;», «&nbsp;Économie, Sociologie, Histoire&nbsp;»...</li>
      <li>la <em>clé dans l'adresse</em> qui est un mot-clé utilisé uniquement dans l'adresse des pages associées à la matière. Il vaut mieux que ce soit un mot unique, court (possiblement abrégé) et sans majuscule. Par exemple, «&nbsp;maths&nbsp;», «&nbsp;phys&nbsp;», «&nbsp;esh&nbsp;»... La clé doit obligatoirement être unique (deux matières ne peuvent pas avoir la même clé).</li>
      <li>l'activation ou non des fonctions et leur accès&nbsp;: voir ci-dessous</li>
      <li>la <em>durée des colles en minutes par élève</em>, qui permet de précalculer automatiquement la durée des colles déclarées.</li>
      <li>la propriété <em>heures de colles insécables</em>, qui permet aussi de précalculer automatiquement la durée des colles déclarées. Voir ci-dessous le détail.</li>
    </ul>
    <h4>Calcul automatique de la durée des colles</h4>
    <p>La durée des colles est automatiquement calculée lors de la saisie des notes réalisée par les colleurs. Les deux paramètres de calculs sont indépendants à chaque matière.</p>
    <p>Les colleurs ne saisissent en réalité que les notes qu'ils donnent, et le système calcule en fonction de ces deux paramètres la durée correspondante.</p>
    <p>La durée est immédiatement calculée. Modifier les paramètres de calcul ne modifie pas les durées déjà calculées, mais seulement celles qui seront déclarées dans l'avenir. Il est possible de régler 20 minutes par élève pendant l'année et 30 pendant les préparations à l'oral par exemple.</p>
    <p>Chaque durée de colle est modifiable par le professeur de la matière, par les comptes administrateurs du Cahier et par les comptes de type lycée, tant que la colle n'a pas été relevée.</p>
    <h4>Gestion des accès</h4>
    <p>L'<em>accès au programmes de colles</em>, l'<em>accès au cahier de texte</em> et l'<em>accès aux documents</em> peuvent être choisis parmi trois catégories de possibilités&nbsp;:</p>
    <ul>
      <li><em>Accès public</em>&nbsp;: ressource accessible par tout visiteur, sans identification. Les moteurs de recherche ont accès aux ressources.</li>
      <li><em>Utilisateurs identifiés</em>&nbsp;: ressource accessible uniquement par les utilisateurs identifiés à déterminer, en fonction de leur type de compte et éventuellement des matières auxquelles ils sont associés (seuls les utilisateurs du type autorisé et associés à la matière peuvent accéder à la ressource). Les associations entre utilisateurs et matières sont modifiables à la page de <a href="utilisateurs-matieres">gestion utilisateurs-matières</a>.</li>
      <li><em>Fonction désactivée</em>&nbsp;: ressource complètement indisponible pour cette matière. La fonction n'apparaît plus dans le menu, y compris pour vous. La page correspondante n'est pas disponible.</li>
    </ul>
    <p>L'<em>accès aux transferts de documents</em> est aussi modifiable. La différence avec les autres fonctions est que l'accès est automatiquement mis à jour à chaque ajout de transfert, pour permettre à tous les utilisateurs d'y accéder. Ce réglage n'est utile que si vous souhaitez supprimer l'accès aux colleurs ou aux comptes de type lycée, car l'accès aux élèves et aux professeurs associés à la matière est garanti, à moins que la fonction soit désactivée.</p>
    <p>Vous pouvez aussi activer ou désactiver les <em>notes de colles</em>.</p>
    <p>Lorsque vous désactivez une fonction, cela ne supprime jamais les éléments qui auraient été déjà saisis ou les documents envoyés, mais bloque simplement l'accès. Ce choix est donc entièrement réversible.</p>
    <p>Les liens dans le menu n'existent que lorsque du contenu est présent. Ils sont visibles de tout visiteur avant identification. Ils disparaissent après identification pour les utilisateurs n'ayant pas accès aux contenus.</p>
    <h4>Suppressions massives</h4>
    <p>Il est aussi possible de supprimer en un seul coup les programmes de colles, le cahier de texte, les répertoires et documents, les notes de colles ou les transferts de documents personnels, en cliquant sur le bouton correspondant. Une confirmation sera demandée. Les boutons ne s'affichent pas s'il n'y a rien à supprimer.</p>
    <p>Il est possible de complètement supprimer une matière en cliquant sur le bouton <span class="icon-supprime"></span>. Une confirmation sera demandée. Cela entraîne la suppression définitive de tous les contenus associés à la matière</p>
  </div>

<?php
fin(true);
?>
