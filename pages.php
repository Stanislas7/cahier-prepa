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

// Accès aux professeurs et administrateurs uniquement. Redirection pour les autres.
if ( $autorisation && ( $autorisation < 5 ) && !$_SESSION['admin'] )  {
  header("Location: https://$domaine$chemin");
  exit();
}
$mysqli = connectsql();
// Si non connecté, demande de connexion
// Si connexion light : on doit répéter son mot de passe pour aller plus loin
// login.php contient fin()
if ( !$autorisation || $_SESSION['light'] )  {
  $titre = 'Modification des pages';
  $actuel = 'pages';
  include('login.php');
}

//////////////
//// HTML ////
//////////////
debut($mysqli,'Modification des pages',$message,$autorisation,'pages',array('action'=>'pages'));

echo <<<FIN

  <div id="icones" data-action="page">
    <a class="icon-ajoute formulaire" title="Ajouter une nouvelle page"></a>
    <a class="icon-aide" title="Aide pour les modifications des pages"></a>
  </div>

FIN;

// Restriction des matières
$requete_matieres = ( $_SESSION['admin'] ) ? '' : "WHERE FIND_IN_SET(m.id,'${_SESSION['matieres']}')";

// Récupération des matières
$select_matieres = '<option value="0">Pas de matière associée</option>';
$resultat = $mysqli->query("SELECT id, nom FROM matieres AS m $requete_matieres ORDER BY ordre");
while ( $r = $resultat->fetch_assoc() )
  $select_matieres .= "<option value=\"${r['id']}\">${r['nom']}</option>";
$resultat->free();

// Récupération des pages
$resultat = $mysqli->query("SELECT m.id, m.nom, MAX(p.ordre) AS max
                            FROM pages AS p LEFT JOIN ( ( SELECT id, ordre, nom FROM matieres) UNION (SELECT  0, 0, 'Général' ) ) AS m ON p.matiere = m.id
                            $requete_matieres GROUP BY m.id ORDER BY m.ordre");
while ( $m = $resultat->fetch_assoc() )  {
  if ( $mid = $m['id'] )  {
    echo "\n  <h3>${m['nom']}</h3>\n";
    $textematiere = ' associés à la matière';
  }
  else 
    $textematiere = '';
  $resultat1 = $mysqli->query("SELECT p.id, p.ordre, p.cle, p.nom, p.titre, p.bandeau, p.protection, p.edition, COUNT(i.id) AS n
                              FROM pages AS p LEFT JOIN infos AS i ON i.page = p.id WHERE p.matiere = ${m['id']} GROUP BY p.id ORDER BY p.ordre");
  while ( $r = $resultat1->fetch_assoc() )  {
    $id = $r['id'];
    $monte = ( $r['ordre'] <= 1 + !$mid ) ? ' style="display:none;"' : '';
    $descend = ( ( $r['ordre'] == $m['max'] ) || ( $id == 1 ) ) ? ' style="display:none;"' : '';
    $nom = ( $mid ) ? "${m['nom']}/${r['nom']}" : $r['nom'];
    // Différence entre page d'accueil et les autres
    if ( $id > 1 )  {
      $suppr = "\n    <a class=\"icon-supprime\" title=\"Supprimer cette page\"></a>";
      $sel_matiere = str_replace("\"$mid\"","\"$mid\" selected",$select_matieres);
      $sel_matiere = <<<FIN

      <p class="ligne"><label for="matiere$id">Matière&nbsp;: </label>
        <select id="matiere$id" name="matiere">$sel_matiere</select>
      </p>
FIN;
      $span = '';
    }
    else  {
      $suppr = $sel_matiere = '';
      // Pour obtenir le bon comportement du script js lors après les montées/descentes
      $span = '<span></span>';
    }
    $supprinfos = ( $r['n'] ) ? "\n      <input type=\"button\" class=\"ligne supprmultiple\" data-type=\"infos\" value=\"Supprimer les ${r['n']} informations de la page\">" : '';
    $propagationdisabled = ( $r['n'] ) ? '' : ' disabled';
    echo <<<FIN

  <article data-id="$id" data-matiere="$mid">
    <a class="icon-aide" title="Aide pour l'édition de cette page"></a>
    <a class="icon-ok" title="Valider les modifications"></a>$suppr
    <a class="icon-descend"$descend title="Déplacer cette page vers le bas"></a>
    <a class="icon-monte"$monte title="Déplacer cette page vers le haut"></a>
    <form>
      <h3 class="edition">$nom</h3>
      <p class="ligne"><label for="titre$id">Titre&nbsp;: </label><input type="text" id="titre$id" name="titre" value="${r['titre']}" size="50" placeholder="Ex: «&nbsp;Informations en [matière]&nbsp;», «&nbsp;À propos du TIPE&nbsp;»"></p>
      <p class="ligne"><label for="nom$id">Nom dans le menu&nbsp;: </label><input type="text" id="nom$id" name="nom" value="${r['nom']}" size="50" placeholder="Pas trop long. Ex: «&nbsp;Informations&nbsp;», «&nbsp;Informations TIPE&nbsp;»"></p>
      <p class="ligne"><label for="cle$id">Clé dans l'adresse&nbsp;: </label><input type="text" id="cle$id" name="cle" value="${r['cle']}" size="30" placeholder="En minuscules et sans espace. Ex: «&nbsp;infos&nbsp;», «&nbsp;tipe&nbsp;»"></p>$sel_matiere
      <p class="ligne" data-protection="${r['protection']}"><label>Accès&nbsp;: </label><select name="protection[]" multiple data-val32="Page invisible"></select></p>
      <p class="ligne" data-edition="${r['edition']}"><label>Édition&nbsp;: </label><select name="edition[]" multiple></select></p>
      <p>Cette page est nécessairement visible et éditable par les professeurs$textematiere.</p>
      <p class="ligne"><label for="propagation$id">Propager ce choix d'accès à chaque information de la page&nbsp;: </label><input type="checkbox" id="propagation$id" name="propagation" value="1"$propagationdisabled></p>
      <p class="ligne"><label for="bandeau$id">Texte de début&nbsp;:</label></p>
      <textarea id="bandeau$id" name="bandeau" rows="2" cols="100" placeholder="Texte qui s'affichera au début de la page">${r['bandeau']}</textarea>
    </form>$supprinfos
  </article>$span

FIN;
  }
  $resultat1->free();
}
$resultat->free();

// Aide et formulaire d'ajout
?>

  <form id="form-ajoute" data-action="ajout-page">
    <h3 class="edition">Ajouter une nouvelle page</h3>
    <p class="ligne"><label for="titre">Titre&nbsp;: </label><input type="text" name="titre" value="" size="50" placeholder="Ex: «&nbsp;Informations en [matière]&nbsp;», «&nbsp;À propos du TIPE&nbsp;»"></p>
    <p class="ligne"><label for="nom">Nom dans le menu&nbsp;: </label><input type="text" name="nom" value="" size="50" placeholder="Pas trop long. Ex: «&nbsp;Informations&nbsp;», «&nbsp;Informations TIPE&nbsp;»"></p>
    <p class="ligne"><label for="cle">Clé dans l'adresse&nbsp;: </label><input type="text" name="cle" value="" size="30" placeholder="En minuscules et sans espace. Ex: «&nbsp;infos&nbsp;», «&nbsp;tipe&nbsp;»"></p>
    <p class="ligne"><label for="matiere">Matière&nbsp;: </label>
      <select name="matiere">
        <?php echo $select_matieres; ?>
      </select>
    </p>
    <p class="ligne"><label>Accès&nbsp;: </label><select name="protection[]" multiple data-val32="Page invisible"></select></p>
    <p class="ligne"><label>Édition&nbsp;: </label><select name="edition[]" multiple></select></p>
    <p>Cette page est nécessairement visible et éditable par les professeurs, éventuellement associés à la matière.</p>
    <p class="ligne"><label for="bandeau">Texte de début&nbsp;:</label></p>
    <textarea name="bandeau" rows="2" cols="100" placeholder="Texte qui s'affichera au début de la page"></textarea>
  </form>

  <div id="aide-page">
    <h3>Aide et explications</h3>
    <p>Il est possible ici d'ajouter avec le bouton <span class="icon-ajoute"></span>, de modifier et de supprimer les pages d'informations.</p>
    <p><strong>Cette page n'est accessible que pour les professeurs et pour les utilisateurs disposant des droits d'administration.</strong></p>
    <p>Les utilisateurs disposant des droits d'administration peuvent ajouter, modifier ou supprimer toute page, indépendamment de la matière éventuellement associée à la page. Les professeurs ne disposant pas des droits d'administration ne peuvent ajouter, modifier ou supprimer que les pages sans matière ou associées à une de leurs matières.</p>
    <p>Chaque page peut être associée à une matière ou non. Sans matière associée, elle sera affichée tout en haut du menu, directement sous les icônes. Avec une matière associée, elle sera affichée dans le menu sous le titre de la matière. Une page apparaît toujours dans le menu des utilisateurs de type professeur, pour pouvoir être éditée. Pour les visiteurs non identifiés, elle n'apparaît que si elle est contient au moins une information. Pour les utilisateurs identifiés, il faut en plus que l'accès à la page leur soit autorisé.</p>
    <p>La première page sans matière associée est la page d'accueil du Cahier de Prépa&nbsp;: il est donc impossible de la supprimer ou de la déplacer.</p>
    <p>Pour toutes les autres pages, il est possible de les supprimer en cliquant sur le bouton <span class="icon-supprime"></span> (une confirmation sera demandée). Si cela est possible, les pages peuvent être déplacées les unes par rapport aux autres dans le menu, à l'aide des boutons <span class="icon-monte"></span> et <span class="icon-descend"></span>.</p>
    <p>Seules les pages sans matière associée ou dont la matière est aussi associée à votre compte sont modifiables.</p>
    <p>Le titre de la première page a un statut spécial&nbsp;: c'est le titre du Cahier de Prépa. Il est donc repris à plusieurs endroits (titre dans la barre de titre du navigateur, titre dans le flux RSS). Le <em>nom dans le menu</em> de cette page est par contre peu important car affiché uniquement lorsque la souris survole l'icône <span class="icon-accueil"></span>.</p>
    <h4>Lire aussi...</h4>
    <p>Une autre <span class="icon-aide"></span>&nbsp;aide dans le cadre de chaque page donne des précisions sur les différents champs associés à chaque page. N'hésitez pas à la consulter&nbsp;!</p>
<?php
  $resultat = $mysqli->query('SELECT GROUP_CONCAT(CONCAT(prenom," ",nom) ORDER BY nom SEPARATOR ", ") FROM utilisateurs WHERE autorisation > 10');
  $administrateurs = $resultat->fetch_row()[0];
  $resultat->free();
  echo <<< FIN
    <h4>Liste des administrateurs</h4>
    <p>Les utilisateurs disposant des droits d'administration de ce Cahier sont <strong>$administrateurs</strong>.</p>
    <p>N'hésitez pas à les contacter pour gérer les listes d'utilisateurs, le <a href="planning">planning</a>, les <a href="groupes">groupes</a> d'utilisateurs, ou ajouter une matière. Vous pouvez modifier tout ce qui correspond à votre matière : <a href="matieres">fonctionnalités et droits d'accès</a> (cahier de textes, programmes de colles, transferts de documents, notes de colles...), pages d'informations.</p>
FIN;
?>
  </div>
  
  <div id="aide-pages">
    <h3>Aide et explications</h3>
    <p>Pour chaque page, vous pouvez modifier&nbsp;:</p>
    <ul>
      <li>le <em>titre</em> qui sera affiché en haut de page et dans la barre de titre du navigateur. Par exemple, «&nbsp;À propos du TIPE&nbsp;».</li>
      <li>le <em>nom dans le menu</em> qui est affiché dans le menu en tant que lien vers la page. Il est préférable qu'il rentre sur une ligne, il faut donc le choisir assez court. Par exemple, «&nbsp;Informations TIPE&nbsp;».</li>
      <li>la <em>clé dans l'adresse</em> qui est un mot-clé utilisé uniquement dans l'adresse de la page. Par convention, il vaut mieux que ce soit un mot unique, court et sans majuscule. Par exemple, «&nbsp;tipe&nbsp;». La clé doit obligatoirement être unique (deux pages ne peuvent pas avoir la même clé).</li>
      <li>le <em>texte de début</em>, qui sera affiché au-dessus des informations de la page. Il s'agit d'une ou deux phrases maximum. Il n'est affiché que si la page contient des informations. Cette case peut être laissée vide.</li>
      <li>l'<em>accès</em> en lecture et en écriture (<em>édition</em>) à la page (voir ci-dessous).</li>
    </ul>
    <p>La case à cocher <em>Propager ce choix d'accès à chaque information</em> permet de modifier les réglages d'accès en lecture et en écriture individuel de chaque information, en les alignant avec ceux choisis pour la page. Les informations dont l'accès en lecture est différent de celui de la page sont repérées par un cadenas <span class="icon-lock"></span> à gauche de leur titre, celles dont l'accès en écriture est différent de celui de la page sont repérées par un crayon <span class="icon-edite"></span>. Valider ce formulaire en cochant cette case doit supprimer toutes les différences de réglage, donc supprimer toutes les icônes à gauche des titre d'informations.</p>
    <p>Si cette case n'est pas cochée, le changement de réglage d'accès à la page n'a donc aucune influence directe sur la visibilité des informations, qui restent éventuellement accessibles sur la page des <a href="recent"><span class="icon-recent"></span>&nbsp;derniers contenus</a>. Cela peut par contre modifier le réglage d'accès en écriture des informations. L'accès en écriture à une information n'est possible que pour les utilisateurs ayant accès à la page et à l'information en lecture (il ne dépend pas de l'accès en écriture à la page).</p>
    <p>Une fois modifié, le formulaire est à valider par un clic sur le bouton <span class="icon-ok"></span>.</p>
    <h4>Suppression de la page ou de ses informations</h4>
    <p>La suppression d'une page entraîne automatiquement la suppression de toutes les informations qui y étaient inscrites.</p>
    <p>Il est aussi possible de supprimer toutes les informations d'une page (sans supprimer la page elle-même) en cliquant sur le bouton correspondant. Celui-ci n'apparaît pas pour les pages vides.</p>
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
    <h4>Accès en lecture et écriture pour chaque information</h4>
    <p>Chaque information peut avoir le même réglage d'accès en lecture et écriture que la page ou non. Ceci est réglable directement à l'écriture d'une nouvelle information, et modifiable pour chaque information existante sur la page correspondant. </p>
    <ul>
      <li>Une information visible/éditable par tous les utilisateurs ayant accès à la page n'a pas d'indication à gauche de son titre. C'est le cas le plus classique et le réglage par défaut à la création d'une nouvelle information.</li>
      <li>Une information visible par une partie des utilisateurs ayant accès à la page est marquée par un cadenas noir <span class="icon-lock"></span> à gauche de son titre. Ceci permet par exemple, sur une page visible sans identification (sans taper son mot de passe), de positionner une information réservée aux utilisateurs identifiés, notamment si elle contient des noms d'élèves ou de colleurs.</li>
      <li>Une information visible par des utilisateurs n'ayant pas accès à la page est marquée par un cadenas rouge <span class="icon-lock mev"></span> à gauche de son titre. Ce réglage, a priori rarement utile, peut permettre d'ajouter une information dans la page des <a href="recent"><span class="icon-recent"></span>&nbsp;derniers contenus</a> pour des utilisateurs qui ne verraient pas la page d'information.</li>
      <li>Une information éditable par moins d'utilisateurs que ceux pouvant éditer la page est marquée par un crayon noir <span class="icon-edite"></span> à gauche de son titre. Ceci peut permettre par exemple, sur une page que pourraient éditer les colleurs, de positionner une information qu'ils ne pourraient pas modifier ni enlever.</li>
      <li>Une information éditable par des utilisateurs n'ayant pas accès en écriture à la page est marquée par un crayon rouge <span class="icon-edite mev"></span> à gauche de son titre. Ceci permet par exemple, sur une page éditable uniquement par les professeurs, de positionner une information éditable par les élèves.</li>
    </ul>
    <p>Un clic sur les éventuels cadenas/crayon à gauche du titre de chaque information permet de voir le détail du réglage correspondant à la page et à l'information.</p>
  </div>

  <div id="aide-ajoute">
    <h3>Aide et explications</h3>
    <p>Ce formulaire permet de créer une nouvelle page d'informations. Il sera validé par un clic sur <span class="icon-ok"></span>, et abandonné (donc supprimé) par un clic sur <span class="icon-ferme"></span>.</p>
    <p>Pour cette nouvelle page, vous devez renseigner&nbsp;:</p>
    <ul>
      <li>le <em>titre</em> qui sera affiché en haut de page et dans la barre de titre du navigateur. Par exemple, «&nbsp;À propos du TIPE&nbsp;».</li>
      <li>le <em>nom dans le menu</em> qui est affiché dans le menu en tant que lien vers la page. Il est préférable qu'il rentre sur une ligne, il faut donc le choisir assez court. Par exemple, «&nbsp;Informations TIPE&nbsp;».</li>
      <li>la <em>clé dans l'adresse</em> qui est un mot-clé utilisé uniquement dans l'adresse de la page. Par convention, il vaut mieux que ce soit un mot unique, court et sans majuscule. Par exemple, «&nbsp;tipe&nbsp;». La clé doit obligatoirement être unique (deux pages ne peuvent pas avoir la même clé).</li>
      <li>le <em>texte de début</em>, qui sera affiché au-dessus des informations de la page. Il s'agit d'une ou deux phrases maximum. Il n'est affiché que si la page contient des informations. Cette case peut être laissée vide.</li>
      <li>l'<em>accès</em> en lecture et en écriture (<em>édition</em>) à la page (voir ci-dessous).</li>
    </ul>
    <p>La case à cocher <em>Propager ce choix d'accès à chaque information</em> permet de modifier les réglages d'accès en lecture et en écriture individuel de chaque information, en les alignant avec ceux choisis pour la page. Les informations dont l'accès en lecture est différent de celui de la page sont repérées par un cadenas <span class="icon-lock"></span> à gauche de leur titre, celles dont l'accès en écriture est différent de celui de la page sont repérées par un crayon <span class="icon-edite"></span>. Valider ce formulaire en cochant cette case doit supprimer toutes les différences de réglage, donc supprimer toutes les icônes à gauche des titre d'informations.</p>
    <p>Si cette case n'est pas cochée, le changement de réglage d'accès à la page n'a donc aucune influence directe sur la visibilité des informations, qui restent éventuellement accessibles sur la page des <a href="recent"><span class="icon-recent"></span>&nbsp;derniers contenus</a>. Cela peut par contre modifier le réglage d'accès en écriture des informations. L'accès en écriture à une information n'est possible que pour les utilisateurs ayant accès à la page et à l'information en lecture (il ne dépend pas de l'accès en écriture à la page).</p>
    <h4>Position de la page dans la liste et dans le menu</h4>
    <p>La page sera automatiquement positionnée en dernière place, éventuellement au sein de la matière choisie. Il sera ensuite possible de la déplacer parmi les autres pages à l'aide des boutons <span class="icon-monte"></span> et <span class="icon-descend"></span>.</p>   
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
    <h4>Accès en lecture et écriture pour chaque informations</h4>
    <p>Chaque information peut avoir le même réglage d'accès en lecture et écriture que la page ou non. Ceci est réglable directement à l'écriture d'une nouvelle information, et modifiable pour chaque information existante sur la page correspondant. </p>
    <ul>
      <li>Une information visible/éditable par tous les utilisateurs ayant accès à la page n'a pas d'indication à gauche de son titre. C'est le cas le plus classique et le réglage par défaut à la création d'une nouvelle information.</li>
      <li>Une information visible par une partie des utilisateurs ayant accès à la page est marquée par un cadenas noir <span class="icon-lock"></span> à gauche de son titre. Ceci permet par exemple, sur une page visible sans identification (sans taper son mot de passe), de positionner une information réservée aux utilisateurs identifiés, notamment si elle contient des noms d'élèves ou de colleurs.</li>
      <li>Une information visible par des utilisateurs n'ayant pas accès à la page est marquée par un cadenas rouge <span class="icon-lock mev"></span> à gauche de son titre. Ce réglage, a priori rarement utile, peut permettre d'ajouter une information dans la page des <a href="recent"><span class="icon-recent"></span>&nbsp;derniers contenus</a> pour des utilisateurs qui ne verraient pas la page d'information.</li>
      <li>Une information éditable par moins d'utilisateurs que ceux pouvant éditer la page est marquée par un crayon noir <span class="icon-edite"></span> à gauche de son titre. Ceci peut permettre par exemple, sur une page que pourraient éditer les colleurs, de positionner une information qu'ils ne pourraient pas modifier ni enlever.</li>
      <li>Une information éditable par des utilisateurs n'ayant pas accès en écriture à la page est marquée par un crayon rouge <span class="icon-edite mev"></span> à gauche de son titre. Ceci permet par exemple, sur une page éditable uniquement par les professeurs, de positionner une information éditable par les élèves.</li>
    </ul>
    <p>Un clic sur les éventuels cadenas/crayon à gauche du titre de chaque information permet de voir le détail du réglage correspondant à la page et à l'information.</p>
  </div>

<?php
fin(true);
?>
