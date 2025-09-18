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
// (mais édition possible uniquement pour les comptes administrateurs)
if ( $autorisation && ( $autorisation < 4 ) && !$_SESSION['admin'] )  {
  header("Location: https://$domaine$chemin");
  exit();
}
$mysqli = connectsql();
// Si non connecté, demande de connexion
// Si connexion light : on doit répéter son mot de passe pour aller plus loin
// login.php contient fin()
if ( !$autorisation || $_SESSION['light'] )  {
  $titre = 'Modification des groupes';
  $actuel = 'groupes';
  include('login.php');
}

// Récupération des utilisateurs et fabrication du formulaire de modification des utilisateurs
$resultat = $mysqli->query('SELECT id, autorisation%10 AS autorisation, IF(nom > \'\',CONCAT(nom,\' \',prenom),CONCAT(\'<em>\',login,\'</em>\')) AS nomcomplet,
                            (mail=\'\') AS pasmail, (LEFT(mdp,1)=\'!\') AS desactive, (LEFT(mdp,1)=\'*\') AS demande, (mdp=\'?\') AS invitation
                            FROM utilisateurs WHERE autorisation > 1 ORDER BY autorisation DESC, nom, prenom, login');
$a = 0;
$utilisateurs = array();
$table = '';
while ( $r = $resultat->fetch_assoc() )  {
  $utilisateurs[$r['id']] = $r['nomcomplet'];
  if ( $a != $r['autorisation'] )  {
    $a = $r['autorisation'];
    switch ( $a )  {
      case 2 : $t = 'Élèves'; break;
      case 3 : $t = 'Colleurs'; break;
      case 4 : $t = 'Lycée'; break;
      case 5 : $t = 'Professeurs'; break;
    }
    $table .= <<<FIN
        <tr class="categorie">
          <th>$t</th>
          <th class="icone"><a class="icon-cocher"></a></th>
        </tr>

FIN;
  }
  if ( $r['pasmail'] == 1 )         $r['nomcomplet'] .= ' (pas d\'adresse électronique)';
  elseif ( $r['desactive'] == 1 )   $r['nomcomplet'] .= ' (compte désactivé)';
  elseif ( $r['demande'] == 1 )     $r['nomcomplet'] .= ' (demande non répondue)';
  elseif ( $r['invitation'] == 1 )  $r['nomcomplet'] .= ' (invitation non répondue)';
  $table .= "        <tr><td>${r['nomcomplet']}</td><td class=\"icone\"><input type=\"checkbox\" id=\"u${r['id']}\"></td></tr>\n";
}
$resultat->free();
$edition = $_SESSION['admin'];

//////////////
//// HTML ////
//////////////
debut($mysqli,'Modication des groupes d\'utilisateurs',$message,$autorisation,'groupes',array('action'=>'groupes'));
if ( $edition )
  echo <<<FIN

  <div id="icones" data-action="page">
    <a class="icon-ajoute formulaire" title="Ajouter un groupe d'utilisateurs"></a>
    <a class="icon-aide" title="Aide pour les modifications des groupes"></a>
  </div>

FIN;
else
  echo <<<FIN

  <div class="annonce">Vous n'avez accès qu'en lecture à cette page. Seuls les utilisateurs disposant des droits d'administration peuvent modifier les groupes.</div>

  <div id="icones">
    <a class="icon-aide" title="Aide pour les modifications des groupes"></a>
  </div>

FIN;

// Récupération et affichage
$resultat = $mysqli->query('SELECT id, nom, IF(mails,\' checked\',\'\') AS mails, IF(notes,\' checked\',\'\') AS notes, utilisateurs FROM groupes ORDER BY nom_nat');
if ( $resultat->num_rows )  {
  while ( $r = $resultat->fetch_assoc() )  {
    $u =  implode(', ',array_intersect_key($utilisateurs,array_flip(explode(',',$r['utilisateurs']))));
    if ( $edition )
      echo <<<FIN

  <article data-id="${r['id']}">
    <h3 class="edition">Groupe <span class="editable" data-champ="nom" data-id="${r['id']}" data-placeholder="Nom du groupe (Ex: 1, A, LV2 Espagnol...)">${r['nom']}</span></h3>
    <a class="icon-aide" title="Aide pour l'édition de ce groupe"></a>
    <a class="icon-supprime" title="Supprimer ce groupe"></a>
    <p class="usergrp"><strong>Utilisateurs&nbsp;:</strong> <span data-uids="${r['utilisateurs']}">$u</span></p>
    <p class="ligne"><label for="mails${r['id']}">Groupe visible lors de l'envoi de courriel</label><input type="checkbox" id="mails${r['id']}"${r['mails']}></p>
    <p class="ligne"><label for="notes${r['id']}">Groupe visible lors de la saisie des notes de colles</label><input type="checkbox" id="notes${r['id']}"${r['notes']}></p>
  </article>

FIN;
    else
      echo <<<FIN

  <article>
    <h3>Groupe ${r['nom']}</h3>
    <p class="usergrp"><strong>Utilisateurs&nbsp;:</strong> $u</p>
    <p class="ligne"><label>Groupe visible lors de l'envoi de courriel</label><input type="checkbox" disabled ${r['mails']}></p>
    <p class="ligne"><label>Groupe visible lors de la saisie des notes de colles</label><input type="checkbox" disabled ${r['notes']}></p>
  </article>

FIN;
  }
  $resultat->free();
}
else
  echo "\n  <article>\n    <h2>Aucun groupe n'est enregistré.</h2>\n  </article>\n";

// Aide et formulaire d'ajout
if ( $edition )  { ?>

  <div id="form-utilisateurs">
    <a class="icon-ok" title="Valider ces utilisateurs"></a>
    <h3>Choix des utilisateurs du groupe </h3>
    <form>
    <table class="utilisateurs">
      <tbody>
<?php echo $table; ?>
      </tbody>
    </table>
    </form>
  </div>
  
  <form id="form-ajoute" data-action="ajout-groupe">
    <h3 class="edition">Ajouter un nouveau groupe</h3>
    <div>
      <input type="text" class="ligne" name="nom" value="" size="50" placeholder="Nom du groupe (Ex: 1, A, LV2 Espagnol...)">
      <p class="usergrp"><strong>Utilisateurs&nbsp;:</strong> <span data-uids="">[Personne]</span></p>
      <p class="ligne"><label for="mails">Groupe visible lors de l'envoi de courriel</label><input type="checkbox" name="mails" value="1"></p>
      <p class="ligne"><label for="notes">Groupe visible lors de la saisie des notes de colles</label><input type="checkbox" name="notes" value="1"></p>
      <input type="hidden" name="uids" value="">
    </div>
  </form>

  <div id="aide-page">
    <h3>Aide et explications</h3>
    <p>Il est possible ici d'ajouter et de modifier des groupes d'utilisateurs. Ces groupes peuvent être utilisés pour deux fonctionnalités&nbsp;:</p>
    <ul>
      <li><em>l'envoi de courriels</em>&nbsp;: ils sont listés à la suite des expéditeurs possibles, pour les sélectionner plus facilement. Ils peuvent regrouper des utilisateurs de tout type.</li>
      <li><em>la saisie de notes de colles</em>&nbsp;: ils permettent une sélection rapide des élèves à noter. Ils sont logiquement censés regrouper des élèves uniquement.</li>
    </ul>
    <p>Les groupes pour l'envoi de courriel ne sont en réalité affichés que pour les utilisateurs colleur/lycée/professeur. Si les élèves peuvent envoyer des courriels (réglage sur la page de <a href="utilisateurs-mails">gestion des courriels</a>), ils ne voient pas les groupes. Il est donc possible de faire des groupes «&nbsp;Colleurs de [matière]&nbsp;» ou «&nbsp;Équipe pédagogique&nbsp;» par exemple.</p>
    <p>Les groupes d'utilisateurs ne se limitent pas aux groupes de colles, il peut être notamment intéressant de créer les deux demi-groupes de la classe ou les groupes d'option par exemple, pour envoyer des courriels aux seuls élèves concernés le cas échéant.</p>
    <p>Les notes de colles peuvent être mises à cheval sur plusieurs groupes. Ce n'est pas grave si parfois un élève ne colle pas avec le reste de son groupe marqué ici.</p>
    <p>Chaque utilisateur peut participer à plusieurs groupes. Chaque groupe peut contenir jusqu'à l'ensemble des utilisateurs, sans limite de type de compte ou de matière associée.</p>
    <p>Un clic sur l'icône <span class="icon-ajoute"></span> permet d'ouvrir le formulaire permettant de créer un nouveau groupe.</p>
    <h4>Modification des groupes</h4>
    <p>Chaque groupe existant peut être supprimé en cliquant sur le bouton <span class="icon-supprime"></span> (une confirmation sera demandée). Cela ne supprime pas les comptes des utilisateurs du groupe.</p>
    <p>Le nom et la liste des utilisateurs de chaque groupe existant sont indiqués par des zones en pointillés et peuvent être modifiés en cliquant sur le bouton <span class="icon-edite"></span> et en validant avec le bouton <span class="icon-ok"></span> qui apparaît alors.</p>
    <p>Les cases à cocher pour définir l'utilisation des groupes sur les courriels ou les notes agissent immédiatement&nbsp;: cocher ou décocher active ou désactive l'utilisation, sans validation supplémentaire.</p>
    <h4>Ordre des groupes</h4>
    <p>Les groupes sont automatiquement classés par ordre alphanumérique.</p>
  </div>
  
  <div id="aide-ajoute">
    <h3>Aide et explications</h3>
    <p>Ce formulaire permet de créer un nouveau groupe d'utilisateurs. Il sera validé par un clic sur <span class="icon-ok"></span>, et abandonné (donc supprimé) par un clic sur <span class="icon-ferme"></span>.</p>
    <p>Les groupes d'utilisateurs peuvent être utilisés pour deux fonctionnalités&nbsp;:</p>
    <ul>
      <li><em>l'envoi de courriels</em>&nbsp;: ils sont listés à la suite des expéditeurs possibles, pour les sélectionner plus facilement. Ils peuvent regrouper des utilisateurs de tout type.</li>
      <li><em>la saisie de notes de colles</em>&nbsp;: ils permettent une sélection rapide des élèves à noter. Ils sont logiquement censés regrouper des élèves uniquement.</li>
    </ul>
    <p>Les groupes pour l'envoi de courriel ne sont en réalité affichés que pour les utilisateurs colleur/lycée/professeur. Si les élèves peuvent envoyer des courriels (réglage sur la page de <a href="utilisateurs-mails">gestion des courriels</a>), ils ne voient pas les groupes. Il est donc possible de faire des groupes «&nbsp;Colleurs de [matière]&nbsp;» ou «&nbsp;Équipe pédagogique&nbsp;» par exemple.</p>
    <p>Les groupes d'utilisateurs ne se limitent pas aux groupes de colles, il peut être notamment intéressant de créer les deux demi-groupes de la classe ou les groupes d'option par exemple, pour envoyer des courriels aux seuls élèves concernés le cas échéant.</p>
    <p>Les notes de colles peuvent être mises à cheval sur plusieurs groupes. Ce n'est pas grave si parfois un élève ne colle pas avec le reste de son groupe marqué ici.</p>
    <p>Les groupes sont automatiquement classés par ordre alphanumérique.</p>
    <h4>Préférence du groupe</h4>
    <p>Le <em>nom du groupe</em> est ce qui apparaîtra derrière la mention &laquo;&nbsp;Groupe&nbsp;&raquo;. Il peut s'agir d'un simple numéro (1,2,3...) pour des groupes de colles, d'une lettre ou d'un mot pour des demi-groupes par exemple (A et B, impairs et pairs...), ou encore d'un nom plus long (&laquo;&nbsp;Colleurs de Mathématiques&nbsp;&raquo;...).</p>
    <p>La liste des <em>utilisateurs</em> est à définir en cliquant sur le bouton <span class="icon-edite"></span> à côté. Une nouvelle fenêtre permet alors de cocher ou décocher les utilisateurs, en cliquant sur les cases ou sur les noms des utilisateurs. L'icône <span class="icon-cocher"></span> permet de cocher tous les utilisateurs d'un même type. Un utilisateur au minimum est obligatoire.</p>
    <p>Les deux cases à cocher <em>Groupe visible lors de l'envoi de courriel</em> et <em>Groupe visible lors de la saisie des notes de colles</em> permettent de choisir l'utilisation du groupe.</p>
  </div>
  
  <div id="aide-groupes">
    <h3>Aide et explications</h3>
    <p>Le <em>nom du groupe</em> et la liste des <em>utilisateurs</em> sont modifiables séparément, en cliquant sur le bouton <span class="icon-edite"></span> correspondant. Chaque contenu éditable est indiqué par une bordure en pointillés.</p>
    <p>Le <em>nom du groupe</em> est ce qui apparaîtra derrière la mention &laquo;&nbsp;Groupe&nbsp;&raquo;. Il peut s'agir d'un simple numéro (1,2,3...) pour des groupes de colles, d'une lettre ou d'un mot pour des demi-groupes par exemple (A et B, impairs et pairs...), ou encore d'un nom plus long (&laquo;&nbsp;Colleurs de Mathématiques&nbsp;&raquo;...).</p>
    <p>La liste des <em>utilisateurs</em> est à définir en cliquant sur le bouton <span class="icon-edite"></span> à côté. Une nouvelle fenêtre permet alors de cocher ou décocher les utilisateurs, en cliquant sur les cases ou sur les noms des utilisateurs. L'icône <span class="icon-cocher"></span> permet de cocher tous les utilisateurs d'un même type. Un utilisateur au minimum est obligatoire.</p>
    <p>Chaque groupe existant peut être supprimé en cliquant sur le bouton <span class="icon-supprime"></span> (une confirmation sera demandée). Cela ne supprime pas les comptes des utilisateurs du groupe.</p>
    <h4>Utilisation du groupe</h4>
    <p>Chaque groupe d'utilisateurs peut être utilisé pour deux fonctionnalités&nbsp;:</p>
    <ul>
      <li>l'envoi de courriels&nbsp;: ils sont listés à la suite des expéditeurs possibles, pour les sélectionner plus facilement. Ils peuvent regrouper des utilisateurs de tout type.</li>
      <li>la saisie de notes de colles&nbsp;: ils permettent une sélection rapide des élèves à noter. Ils sont logiquement censés regrouper des élèves uniquement.</li>
    </ul>
    <p>Les groupes pour l'envoi de courriel ne sont en réalité affichés que pour les utilisateurs colleur/lycée/professeur. Si les élèves peuvent envoyer des courriels (réglage sur la page de <a href="utilisateurs-mails">gestion des courriels</a>), ils ne voient pas les groupes. Il est donc possible de faire des groupes «&nbsp;Colleurs de [matière]&nbsp;» ou «&nbsp;Équipe pédagogique&nbsp;» par exemple.</p>
    <p>Les groupes d'utilisateurs ne se limitent pas aux groupes de colles, il peut être notamment intéressant de créer les deux demi-groupes de la classe ou les groupes d'option par exemple, pour envoyer des courriels aux seuls élèves concernés le cas échéant.</p>
    <p>Les notes de colles peuvent être mises à cheval sur plusieurs groupes. Ce n'est pas grave si parfois un élève ne colle pas avec le reste de son groupe marqué ici.</p>
    <p>Les cases à cocher pour définir l'utilisation des groupes sur les courriels ou les notes agissent immédiatement&nbsp;: cocher ou décocher active ou désactive l'utilisation, sans validation supplémentaire.</p>
    <h4>Ordre des groupes</h4>
    <p>Les groupes sont automatiquement classés par ordre alphanumérique.</p>
  </div>

<?php 
}
// Cas des non-administrateurs : pas de formulaires et aide simplifiée
else  { 
?>

  <div id="aide-groupes">
    <h3>Aide et explications</h3>
    <p>Il est possible ici de visualiser les groupes d'utilisateurs. Les utilisateurs disposant des droits d'administration ont la possibilité de modifier, ajouter et supprimer ces groupes.</p>
    <p>Ces groupes peuvent être utilisés pour deux fonctionnalités&nbsp;:</p>
    <ul>
      <li><em>l'envoi de courriels</em>&nbsp;: ils sont listés à la suite des expéditeurs possibles, pour les sélectionner plus facilement. Ils peuvent regrouper des utilisateurs de tout type.</li>
      <li><em>la saisie de notes de colles</em>&nbsp;: ils permettent une sélection rapide des élèves à noter. Ils sont logiquement censés regrouper des élèves uniquement.</li>
    </ul>
    <p>Les groupes pour l'envoi de courriel ne sont en réalité affichés que pour les utilisateurs colleur/lycée/professeur. Si les élèves peuvent envoyer des courriels (réglage sur la page de <a href="utilisateurs-mails">gestion des courriels</a>), ils ne voient pas les groupes. Il est donc possible de faire des groupes «&nbsp;Colleurs de [matière]&nbsp;» ou «&nbsp;Équipe pédagogique&nbsp;» par exemple.</p>
    <p>Les groupes d'utilisateurs ne se limitent pas aux groupes de colles, il peut être notamment intéressant de créer les deux demi-groupes de la classe ou les groupes d'option par exemple, pour envoyer des courriels aux seuls élèves concernés le cas échéant.</p>
    <p>Les notes de colles peuvent être mises à cheval sur plusieurs groupes. Ce n'est pas grave si parfois un élève ne colle pas avec le reste de son groupe marqué ici.</p>
    <p>Chaque utilisateur peut participer à plusieurs groupes. Chaque groupe peut contenir jusqu'à l'ensemble des utilisateurs, sans limite de type de compte ou de matière associée.</p>
<?php
  $messageprof = ( $autorisation == 5 ) ? 'Vous pouvez modifier tout ce qui correspond à votre matière : <a href="matieres">fonctionnalités et droits d\'accès</a> (cahier de textes, programmes de colles, transferts de documents, notes de colles...), <a href="pages">pages d\'informations</a>.' : '';
  $resultat = $mysqli->query('SELECT GROUP_CONCAT(CONCAT(prenom," ",nom) ORDER BY nom SEPARATOR ", ") FROM utilisateurs WHERE autorisation > 10');
  $administrateurs = $resultat->fetch_row()[0];
  $resultat->free();
  echo <<< FIN
    <h4>Liste des administrateurs</h4>
    <p>Les utilisateurs disposant des droits d'administration de ce Cahier sont <strong>$administrateurs</strong>.</p>
    <p>N'hésitez pas à les contacter pour gérer les listes d'utilisateurs, le <a href="planning">planning</a>, les groupes d'utilisateurs, ou ajouter une matière. $messageprof</p>
  </div>

FIN;
}
// Laisser true pour voir l'aide
fin(true);
?>
