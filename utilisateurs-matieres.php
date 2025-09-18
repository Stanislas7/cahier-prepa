<?php
// Sécurité
define('OK',1);
// Configuration
include('config.php');
// Fonctions
include('fonctions.php');

// Comptes en attente de validation de l'équipe pédagogique :
// * en début de mdp (donc 41 caractères)
// Comptes en attente de réponse de l'utilisateur :
// ? en début de mdp (non défini, donc 1 caractère)
// Comptes désactivés :
// ! en début de mdp (donc 41 caractères)
// Comptes actifs :
// mdp valant un sha1 (donc "mdp > '0'" dans les where)

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
  $titre = 'Modification des associations utilisateurs-matières';
  $actuel = 'utilisateurs-matieres';
  include('login.php');
}

// Valeur par défaut de la recherche
$requete = 'WHERE XXX';

// Types de comptes
// Pour chaque type : nom complet singulier , clé, nom complet du type, requete sql 
$autorisations = array(
  5=>array('Professeur',  'profs',     'Professeurs',  'autorisation%10 = 5'),
  4=>array('Lycée',       'lycee',     'Lycée',        'autorisation%10 = 4'),
  3=>array('Colleur',     'colleurs',  'Colleurs',     'autorisation%10 = 3'),    
  2=>array('Élève',       'eleves',    'Élèves',       'autorisation%10 = 2'),    
  1=>array('Invité',      'invites',   'Invités',      'autorisation%10 = 1') );
$select_types = '';
foreach ( $autorisations as $v => $a )  {
  $select_types .= "      <option value=\"${a[1]}\">${a[0]}</option>\n";
  if ( isset($_REQUEST['type']) && ( $a[1] == $_REQUEST['type'] ) )  {
    $cle_type = $a[1];
    $requete = "WHERE autorisation%10 = $v AND XXX";
  }
}

// Récupération des matières
$resultat = $mysqli->query('SELECT id, nom, cle FROM matieres ORDER BY ordre');
$matieres = array();
$select_matieres = $th_matieres = $iconesmultiples = '';
while ( $r = $resultat->fetch_assoc() )  {
  $matieres[$r['id']] = 0;
  $select_matieres .= "      <option value=\"${r['cle']}\">${r['nom']}</option>\n";
  $th_matieres .= "          <th class=\"vertical\"><span id=\"m${r['id']}\">${r['nom']}</span></th>\n";
  $iconesmultiples .= "\n          <th class=\"icone\"><span class=\"icon-ok\" data-id=\"${r['id']}\"></span></th>";
  if ( isset($_REQUEST['matiere']) && ( $r['cle'] == $_REQUEST['matiere'] ) )  {
    $cle_matiere = $r['cle'];
    $requete = "JOIN matieres AS m ON FIND_IN_SET(m.id,u.matieres) $requete AND m.id = ${r['id']}";
  }
}
$resultat->free();
$iconesmultiples .= "\n          <th class=\"icone\"><span class=\"icon-cocher\"></span></th>";
if ( !$edition = $_SESSION['admin'] )
  $iconesmultiples  = '';

//////////////
//// HTML ////
//////////////
debut($mysqli,'Modification des associations utilisateurs-matières',$message,$autorisation,'utilisateurs-matieres');
if ( !$edition )
  echo "\n  <div class=\"annonce\">Vous n'avez accès qu'en lecture à cette page. Seuls les utilisateurs disposant des droits d'administration peuvent modifier les associations utilisateurs-matieres.</div>\n";
?>

  <div id="icones" data-action="page">
    <a class="icon-aide" title="Aide pour les modifications des associations utilisateurs-matières"></a>
  </div>

  <p id="rechercheutilisateurs" class="topbarre">
    <select id="type" onchange="window.location='?type='+this.value+'&amp;matiere='+$(this).next().val();">
      <option value="tout">Filtrer par type</option>
<?php echo( isset($cle_type) ? str_replace("\"$cle_type\"","\"$cle_type\" selected",$select_types) : $select_types ); ?>
    </select>
    <select id="matiere" onchange="window.location='?type='+$(this).prev().val()+'&amp;matiere='+this.value;">
      <option value="tout">Filtrer par matière</option>
<?php echo( isset($cle_matiere) ? str_replace("\"$cle_matiere\"","\"$cle_matiere\" selected",$select_matieres) : $select_matieres ); ?>
    </select>
    <span class="icon-recherche" onclick="$(this).next().val('').change();"></span>
    <input type="text" value="" placeholder="Rechercher un nom, prénom...">
  </p>

  <article>
    <h3>Liste des utilisateurs</h3>
    <table id="umats" class="utilisateurs" style="display: none;">
      <thead>
        <tr>
          <th></th>
<?php echo $th_matieres; ?>
        </tr>
      </thead>
      <tbody>
<?php
// Recherche des couples (utilisateurs, matières) à ne pas modifier : colles ou transferts utilisés
$matieresbloquees = array();
$resultat = $mysqli->query('SELECT utilisateur, GROUP_CONCAT(matiere) AS matieres FROM (
                              SELECT DISTINCT utilisateur, matiere FROM transferts JOIN transdocs ON transdocs.transfert = transferts.id
                              UNION SELECT DISTINCT eleve, matiere FROM transferts JOIN transdocs ON transdocs.transfert = transferts.id
                              UNION SELECT DISTINCT colleur, matiere FROM heurescolles
                              UNION SELECT DISTINCT eleve, matiere FROM notescolles ) AS t GROUP BY utilisateur');
if ( $resultat->num_rows )
  while ( $r = $resultat->fetch_row() )
    $matieresbloquees[$r[0]] = ",${r[1]},";

// Requête générale
$requete = "SELECT u.id, IF(LENGTH(u.nom),CONCAT(u.nom,' ',prenom),CONCAT('<em>',login,'</em>')) AS nomprenom,
            autorisation%10 AS autorisation, autorisation>10 AS admin, matieres
            FROM utilisateurs AS u $requete ORDER BY nomprenom";

// Fonction de remplissage des lignes
// $qualificatif est le type en toutes lettres pour les comptes non validés ou désactivés
// $autorisation est le type (numérique) affiché, pour les comptes valides
function ligne($r,$matieres,$matieresbloquees,$qualificatif,$autorisation = 0)  {
  if ( ( $autorisation == 3 ) && ( $r['autorisation'] == 5 ) )  {
    $qualificatif = 'Professeur';
    $r['id'] = "c${r['id']}";
  }
  if ( $r['admin'] )
    $qualificatif .= ( strlen($qualificatif) ? ', ' : '' ).'Administrateur';
  if ( $qualificatif )
    $r['nomprenom'] .= " ($qualificatif)";
  echo "        <tr data-id=\"${r['id']}\">\n          <td>${r['nomprenom']}</td>\n          ";
  foreach ( array_filter(explode(',',$r['matieres'])) as $mid )  {
    // 1 : utilisateur "normal" (association supprimable) ; 2 : association bloquée ; 
    // 3 : prof, colleur dans cette matière ; 4 : colleur, prof dans cette matière ;
    // 3&4 : association non supprimable car à voir dans l'autre partie du tableau
    if ( $mid[0] == 'c' )
      $matieres[substr($mid,1)] = 1 + 2*( $autorisation == 5);
    else
      $matieres[$mid] = 1 + 3*( $r['id'][0] == 'c' );
  }
  if ( $GLOBALS['edition'] )  {
    foreach ( $matieres as $mid => $ok )  {
      if ( ( $ok == 1 ) && ( strpos($matieresbloquees,",$mid,") !== false ) )
        $ok = 2;
      echo "<td class=\"icone\">$mid|$ok</td>";
    }
    echo "<td class=\"icone\"><input type=\"checkbox\"></td>\n        </tr>\n";
  }
  else
    foreach ( $matieres as $mid => $ok )
      switch ( $ok )  {
        case 0: echo '<td class="icone"><span class="icon-nok" title="Matière non associée"></span></td>'; break;
        case 1: echo ( strpos($matieresbloquees,",$mid,") !== false ) ? '<td class="icone fixe"><span class="icon-ok" title="Matière associée non modifiable"></span></td>' : '<td class="icone"><span class="icon-ok" title="Matière associée"></span></td>'; break;
        case 4: echo '<td class="icone"><strong title="Professeur dans cette matière">P</strong></td>'; break;
        case 3: echo '<td class="icone"><strong title="Colleur dans cette matière">C</strong></td>';
      }
}

// Récupération des demandes à valider
$resultat = $mysqli->query(str_replace('XXX','mdp LIKE \'*%\'',$requete));
if ( $n = $resultat->num_rows )  {
  echo "        <tr class=\"categorie\">\n          <th>Demandes en attente de validation ($n)</th>$iconesmultiples\n        </tr>\n";
  while ( $r = $resultat->fetch_assoc() )
    ligne($r,$matieres,$matieresbloquees[$r['id']] ?? '',$autorisations[$r['autorisation']][0]);
  $resultat->free();
}

// Récupération des invitations non répondues
$resultat = $mysqli->query(str_replace('XXX','mdp = \'?\'',$requete));
if ( $n = $resultat->num_rows )  {
  echo "        <tr class=\"categorie\">\n          <th>Invitations envoyées en attente de réponse ($n)</th>$iconesmultiples\n        </tr>\n";
  while ( $r = $resultat->fetch_assoc() )
    ligne($r,$matieres,$matieresbloquees[$r['id']] ?? '',$autorisations[$r['autorisation']][0]);
  $resultat->free();
}

// Décompte total des utilisateurs (comptes validés et non désactivés) en fonction de leur type
foreach ( $autorisations as $v => $auto)  {
  $resultat = $mysqli->query(str_replace('XXX',"mdp > '0' AND ( autorisation%10=$v ".( $v == 3 ? 'OR LOCATE(\'c\',matieres) )' : ')' ),$requete));
  if ( $n = $resultat->num_rows )  {
    $ajout = ( ( $v == 3 ) && $edition ) ? ' <span id="ajoutprof">Ajouter un professeur aux colleurs</span>' : '';
    echo "        <tr class=\"categorie\">\n          <th>{$auto[2]} ($n)$ajout</th>$iconesmultiples\n        </tr>\n";
    while ( $r = $resultat->fetch_assoc() )
      ligne($r,$matieres,$matieresbloquees[$r['id']] ?? '','',$v);
    $resultat->free();
  }
}

// Récupération des comptes désactivés
$resultat = $mysqli->query(str_replace('XXX','mdp LIKE \'!%\'',$requete));
if ( $n = $resultat->num_rows )  {
  echo "        <tr class=\"categorie\">\n          <th>Comptes désactivés ($n)</th>$iconesmultiples\n        </tr>\n";
  while ( $r = $resultat->fetch_assoc() )
    ligne($r,$matieres,$matieresbloquees[$r['id']] ?? '',$autorisations[$r['autorisation']][0]);
  $resultat->free();
}
?>
      </tbody>
    </table>
  </article>

<?php
// Aide et formulaire d'ajout
// L'id "selmult" de la table ne sert qu'à l'affichage (style.css)
if ( $edition )  {
?>
  <div id="form-ajoutprof">
    <h3 class="edition">Ajouter un professeur à la liste des colleurs</h3>
    <form>
      <p>Vous pouvez choisir un des professeurs de la classe pour l'ajouter dans le tableau des colleurs, afin de lui ajouter la qualité de colleur dans une matière autre que celle où il enseigne déjà. Le professeur concerné conservera ses droits de professeur dans sa propre matière mais n'aura que des droits de colleurs dans la matière ajoutée.</p>
      <p>Il suffit de cliquer sur le nom du professeur pour l'ajouter.</p>
      <table id="selmult">
        <tbody>
        </tbody>
      </table>
    </form>
  </div>

  <div id="aide-page">
    <h3>Aide et explications</h3>
    <p>Il est possible ici de modifier les associations entre utilisateurs et matières.</p>
    <p><strong>Cette page est visible par tous les utilisateurs ayant un compte de type professeur ou lycée, ou ayant les droits d'administration. Mais elle n'est modifiable que par les utilisateurs disposant des droits d'administration.</strong></p>
    <p>L'ajout, la suppression et la désactivation des comptes utilisateurs est possible à la page de <a href="utilisateurs">gestion des utilisateurs</a>, accessible uniquement pour les utilisateurs disposant des droits d'adminstration. La modification de l'identifiant ou du type d'un compte est aussi modifiable sur cette même page.</p>
    <p>La liste des comptes affichée peut être filtrée à l'aide des menus déroulants dans la barre de recherche située au-dessus du tableau.</p>
    <h4>Modification unique</h4>
    <p>Chaque compte utilisateur, en ligne, peut être associé (<span class="icon-ok"></span>) ou non associé (<span class="icon-nok"></span>) à une matière, en colonne. Un clic sur un de ces boutons commute entre autorisation et interdiction. La modification est immédiate, l'utilisateur n'a pas besoin de se reconnecter pour en voir l'effet. Vous pouvez bien sûr modifier vos propres matières, il faut alors simplement recharger la page pour voir le menu avec/sans la matière concernée.</p>
    <h4>Cas des associations non modifiables</h4>
    <p>Supprimer une association entre un utilisateur et une matière n'est possible que si cela ne supprime pas des contenus parmi les notes de colles ou les transferts de documents. Le bouton <span class="icon-ok"></span> est donc grisé et non cliquable s'il s'agit par exemple d'un colleur ayant déjà mis des notes ou d'un élève ayant déjà reçus des documents. Il faut tout d'abord supprimer ces contenus si l'on veut supprimer l'association. Cela permet d'éviter les erreurs de manipulation.</p>
    <h4>Cas des multi-casquettes</h4>
    <p>Les professeurs peuvent être associés à plusieurs matières, mais il n'est en général pas nécessaire d'associer un professeur à toutes les matières, même pour ceux qui veulent tout gérer. Cela rend le menu très long, sans intérêt. Il est préférable que chacun ne soit associé qu'à sa (ou ses) matières. Posséder les droits d'administration suffit pour tout gérer.</p>
    <p>Un compte peut être de type professeur pour une matière et colleur pour une autre. Il a alors la possibilité de tout modifier dans la matière où il est associé en tant que professeur, et a les mêmes droits que les autres professeurs pour les ressources générales (page sans matière, documents généraux, agenda). Mais il n'a que les droits de colleur et l'affichage correspondant, dans la matière où il est associé comme colleur. Cet utilisateur apparaît dans les deux parties du tableau, avec un C (non modifiable) dans la partie <em>Professeurs</em> pour la matière où il est colleur, et un P (non modifiable) dans la partie <em>Colleurs</em> pour la matière où il est professeur.</p>
    <p>Pour réaliser cela, il faut cliquer sur <em>Ajouter un professeur</em> dans la partie <em>Colleurs</em> et sélectionner le professeur en question. Une ligne modifiable apparaît alors, où l'on peut cliquer sur un bouton <span class="icon-nok"></span> pour l'associer en tant que colleur.</p>
    <h4>Modification multiple</h4>
    <p>Il est possible de réaliser une action identique (association ou désassociation) sur plusieurs comptes utilisateurs simultanément en cochant les cases en bout de ligne et en cliquant sur les boutons d'action situés sur les lignes d'entêtes. Le bouton <span class="icon-cocher"></span> permet de cocher tous les comptes d'un même type. </p>
    <p>Sur chaque colonne, le bouton qui apparaît sur la ligne d'entête est celui permettant de modifier le plus d'associations. Par exemple, sur 10 utilisateurs, si 4 seulement sont associés à la matière, alors un bouton d'association <span class="icon-ok"></span> apparaît, permettent d'associer aussi les 6 autres. Au contraire, si 7 sont déjà associés, un bouton <span class="icon-nok"></span> permet de les désassocier.</p>
    <p>Si une association est matérialisée par un bouton <span class="icon-ok"></span> grisé et non cliquable, il n'y a pas de risque de suppression de cette association, qui sera ignorée si elle est demandée.</p>
  </div>

<?php
}
// Cas des non-administrateurs
else  {
?>
  <div id="aide-page">
    <h3>Aide et explications</h3>
    <p>Il est possible ici de visualiser les associations entre utilisateurs et matières. Les utilisateurs disposant des droits d'administration ont la possibilité de modifier ces associations.</p>
    <p>La liste des comptes affichée peut être filtrée à l'aide des menus déroulants dans la barre de recherche située au-dessus du tableau.</p>
    <p>Chaque compte utilisateur, en ligne, peut être associé (<span class="icon-ok"></span>) ou non associé (<span class="icon-nok"></span>) à une matière, en colonne.</p>
    <h4>Cas des associations non modifiables</h4>
    <p>Les associations entre un utilisateur et une matière qui correspondent à des contenus parmi les notes de colles ou les transferts de documents ne sont pas modifiables et apparaîssent avec une icône <span class="icon-ok"></span> grisée.</p>
    <h4>Cas des multi-casquettes</h4>
    <p>Un compte peut être de type professeur pour une matière et colleur pour une autre. Il a alors la possibilité de tout modifier dans la matière où il est associé en tant que professeur, et a les mêmes droits que les autres professeurs pour les ressources générales (page sans matière, documents généraux, agenda). Mais il n'a que les droits de colleur et l'affichage correspondant, dans la matière où il est associé comme colleur. Cet utilisateur apparaît dans les deux parties du tableau, avec un C (non modifiable) dans la partie <em>Professeurs</em> pour la matière où il est colleur, et un P (non modifiable) dans la partie <em>Colleurs</em> pour la matière où il est professeur.</p>
<?php
  $messageprof = ( $autorisation == 5 ) ? 'Vous pouvez modifier tout ce qui correspond à votre matière : <a href="matieres">fonctionnalités et droits d\'accès</a> (cahier de textes, programmes de colles, transferts de documents, notes de colles...), <a href="pages">pages d\'informations</a>.' : '';
  $resultat = $mysqli->query('SELECT GROUP_CONCAT(CONCAT(prenom," ",nom) ORDER BY nom SEPARATOR ", ") FROM utilisateurs WHERE autorisation > 10');
  $administrateurs = $resultat->fetch_row()[0];
  $resultat->free();
  echo <<< FIN
    <h4>Liste des administrateurs</h4>
    <p>Les utilisateurs disposant des droits d'administration de ce Cahier sont <strong>$administrateurs</strong>.</p>
    <p>N'hésitez pas à les contacter pour gérer les listes d'utilisateurs, le <a href="planning">planning</a>, les <a href="groupes">groupes</a> d'utilisateurs, ou ajouter une matière. $messageprof</p>
  </div>

FIN;
}
fin(true);
?>
