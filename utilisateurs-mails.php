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
  $titre = 'Gestion des utilisateurs et des courriels';
  $actuel = 'utilisateurs-mails';
  include('login.php');
}

/////////////////////////////////////////////////////////////
// Exportation des utilisateurs en xls : Version partielle //
/////////////////////////////////////////////////////////////
// Version complète, pour les profs et admins, dans utilisateurs.php
// Ne contient que les noms - prénoms - adresses mails
// Exportation uniquement si aucun header déjà envoyé
if ( isset($_REQUEST['xls']) && !headers_sent() )  {
  // Recherche des utilisateurs concernés : comptes validés hors comptes invités
  // et hors comptes sans nom et sans mail (identifiant seul)
  $resultat = $mysqli->query('SELECT nom, prenom, IF(LENGTH(mail),mail,"Pas d\'adresse") AS mail, autorisation%10 AS autorisation FROM utilisateurs
                              WHERE mdp > \'0\' AND autorisation > 1 AND nom > \'\' OR prenom > \'\' OR mail != \'\'
                              ORDER BY autorisation DESC, IF(LENGTH(nom),CONCAT(nom,prenom),login)');
  $mysqli->close();
  if ( $resultat->num_rows )  {
    // Fonction de saisie
    function saisie_chaine($l, $c, $v)  {
      echo pack("ssssss", 0x204, 8 + strlen($v), $l, $c, 0, strlen($v)).$v;
      return;
    }
    // Correspondance autorisation-type de compte
    $categories = array(2=>'Élève',3=>'Colleur',4=>'Lycée',5=>'Professeur');
    // Envoi des headers
    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=utilisateurs.xls");
    header("Content-Transfer-Encoding: binary");
    // Début du fichier xls
    echo pack("sssss", 0x809, 6, 0, 0x10, 0);
    // Remplissage
    saisie_chaine(0, 0, 'Nom');
    saisie_chaine(0, 1, utf8_decode('Prénom'));
    saisie_chaine(0, 2, utf8_decode('Adresse électronique'));
    saisie_chaine(0, 3, utf8_decode('Catégorie'));
    $i = 0;
    while ( $r = $resultat->fetch_assoc() )  {
      saisie_chaine(++$i, 0, utf8_decode($r['nom']));
      saisie_chaine($i, 1, utf8_decode($r['prenom']));
      saisie_chaine($i, 2, utf8_decode($r['mail']));
      saisie_chaine($i, 3, utf8_decode($categories[$r['autorisation']]));
    }
    // Fin du fichier xls
    echo pack("ss", 0x0A, 0x00);
    $resultat->free();
  }
  exit();
}

////////////////////////////////////////////////////////////////////////////////
// Préférence d'autorisation d'envoi de courriels 
// Stockée dans la table prefs, nom = autorisation_mails
//
// $aut_envoi est une valeur numérique contenant l'ensembles des accès entre
// les quatres groupes P (professeurs), L (lycée), C (colleurs) et E (élèves).
// C'est la représentation décimale de la valeur binaire
//    PP PL PC PE LP LL LC LE CP CL CC CE EP EL EC EE
// où XY correspond à l'autorisation de X à envoyer un courriel à Y (1=oui).
// Pour accéder aux autorisations du groupe numéro $n (2->E,3->C,4->L,5->P),
// il faut décaler $autorisation de 4*$n bits et garder les 4 bits faibles, soit
//    ( $aut_envoi >> 4*($n-2) ) & 15
// Pour accéder à l'autorisation du groupe numéro $n vers le groupe numéro $m, 
//    ( $aut_envoi >> 4*($n-2)+$m-2 ) & 1
////////////////////////////////////////////////////////////////////////////////

// Limitation de la recherche
$requete = 'WHERE XXX'; 

// Types de comptes
// Pour chaque type : nom complet (dans le select et le tableau), clé, nom affiché dans le tableau des droits d'envoi
$autorisations = array(5=>array('Professeurs','profs','les professeurs'), 4=>array('Lycée','lycee','le lycée'),
                       3=>array('Colleurs','colleurs','les colleurs'),    2=>array('Élèves','eleves','les élèves') );
$select_types = '';
foreach ( $autorisations as $v => $a )  {
  if ( isset($_REQUEST['type']) && ( $a[1] == $_REQUEST['type'] ) )  {
    $select_types .= "          <option value=\"${a[1]}\" selected>${a[0]}</option>\n";
    $requete = "WHERE autorisation%10 = $v AND XXX";
  }
  else
    $select_types .= "          <option value=\"${a[1]}\">${a[0]}</option>\n";
}

// Récupération des matières
$resultat = $mysqli->query('SELECT id, nom, cle FROM matieres ORDER BY ordre');
$select_matieres = '';
while ( $r = $resultat->fetch_assoc() )  {
  if ( isset($_REQUEST['matiere']) && ( $r['cle'] == $_REQUEST['matiere'] ) )  {
    $select_matieres .= "      <option value=\"${r['cle']}\" selected>${r['nom']}</option>\n";
    $requete = "JOIN matieres AS m ON FIND_IN_SET(m.id,u.matieres) $requete AND m.id = ${r['id']}";
  }
  else 
    $select_matieres .= "      <option value=\"${r['cle']}\">${r['nom']}</option>\n";
}
$resultat->free();

// Récupération des autorisations d'envoi
$resultat = $mysqli->query('SELECT val FROM prefs WHERE nom = "autorisation_mails"');
$aut_envoi = $resultat->fetch_row()[0];
$resultat->free();
$edition = $_SESSION['admin'];

//////////////
//// HTML ////
//////////////
// La data-action sert exceptionnellement au format CSS pour les pages de moins de 500 pixels de large
debut($mysqli,'Gestion des utilisateurs et des courriels',$message,$autorisation,'utilisateurs-mails',array('action'=>'utilisateurs'));
if ( !$edition )
  echo "\n  <div class=\"annonce\">Vous n'avez accès qu'en lecture à cette page. Seuls les utilisateurs disposant des droits d'administration peuvent modifier les comptes utilisateurs.</div>\n";
?>

  <div id="icones">
    <a class="icon-download" href="utilisateurs?xls" title="Télécharger la liste des utilisateurs en xls"></a>
    <a class="icon-aide" title="Aide pour les modifications des courriels"></a>
  </div>

  <article>
    <a class="icon-ferme" title="Fermer ce tableau" onclick="$(this).parent().hide();"></a>
    <h3>Possibilités d'envoi de courriels</h3>
    <table <?php echo $edition ? 'id="envoimails" ' : ''; ?>class="utilisateurs">
      <tbody>
        <tr>
          <th colspan="2"></th>
          <th class="vertical"><span>Vers les professeurs</span></th>
          <th class="vertical"><span>Vers le lycée</span></th>
          <th class="vertical"><span>Vers les colleurs</span></th>
          <th class="vertical"><span>Vers les éleves</span></th>
        </tr>
<?php
if ( $edition )  {
  foreach ( $autorisations as $v => $a )  {
    $envoi = ( $aut_envoi >> 4*($v-2) ) & 15;
    echo <<<FIN
        <tr data-id="$v">
          <th>Par ${a[2]}</th><th class="icones"><span class="icon-ok" title="Établir l'autorisation d'envoi générale par ${a[2]} à tous les utilisateurs"></span>&nbsp;<span class="icon-nok" title="Supprimer l'autorisation d'envoi par ${a[2]} à tous les utilisateurs"></span></th>
          
FIN;
    for ( $i=5; $i>=2; $i-- )  {
      $ok = ( $envoi >> $i-2 ) & 1;
      echo "<td class=\"icone\">$i|$ok</td>";
    }
    echo "        </tr>\n";
  } 
}
else  {
  foreach ( $autorisations as $v => $a )  {
    $envoi = ( $aut_envoi >> 4*($v-2) ) & 15;
    echo <<<FIN
        <tr>
          <th colspan="2">Par ${a[2]}</th>
          
FIN;
    for ( $i=5; $i>=2; $i-- )  {
      $icone = ( ( $envoi >> $i-2 ) & 1 ) ? '<span class="icon-ok" title="Envoi autorisé"></span>' : '<span class="icon-nok" title="Envoi interdit"></span>';
      echo "<td class=\"icone\">$icone</td>";
    }
    echo "        </tr>\n";
  }
}
?>
      </tbody>
    </table>
  </article>

  <p id="rechercheutilisateurs" class="topbarre">
    <select id="type" onchange="window.location='?type='+this.value+'&amp;matiere='+$(this).next().val();">
      <option value="tout">Filtrer par type</option>
<?php echo $select_types; ?>
    </select>
    <select id="matiere" onchange="window.location='?type='+$(this).prev().val()+'&amp;matiere='+this.value;">
      <option value="tout">Filtrer par matière</option>
<?php echo $select_matieres; ?>
    </select>
    <span class="icon-recherche" onclick="$(this).next().val('').change();"></span>
    <input type="text" value="" placeholder="Rechercher un nom, prénom...">
  </p>

  <article>
    <h3>Liste des utilisateurs</h3>
    <table id="u" class="utilisateurs">
      <tbody>
<?php
$requete = "SELECT u.id, u.nom, prenom, IF(LENGTH(mail),mail,\"Pas d'adresse\") AS mail, mailexp FROM utilisateurs AS u $requete ORDER BY u.nom, prenom";

// Fonction d'affichage des lignes du tableau
// $r : les données utilisateurs
function ligne($r)  {
  $edite = ( $GLOBALS['edition'] ? '<a class="icon-edite" title="Éditer ce compte"></a>' : '' );
  echo <<<FIN
        <tr data-id="${r['id']}">
          <td>${r['nom']} ${r['prenom']}</td>
          <td>${r['mailexp']}</td>
          <td>${r['mail']}</td>
          <td class="icones">$edite</td>
        </tr>

FIN;
}

// Récupération des demandes à valider
$resultat = $mysqli->query(str_replace('XXX','mdp LIKE \'*%\'',$requete));
if ( $n = $resultat->num_rows )  {
  echo  <<<FIN
        <tr class="categorie"><th colspan="4">Demandes en attente de validation ($n)</th></tr>
        <tr><th>Identité</th><th>Nom affiché</th><th>Adresse électronique</th><th></th></tr>

FIN;
  while ( $r = $resultat->fetch_assoc() )
    ligne($r);
  $resultat->free();
}

// Récupération des invitations non répondues
$resultat = $mysqli->query(str_replace('XXX','mdp = \'?\'',$requete));
if ( $n = $resultat->num_rows )  {
  echo  <<<FIN
        <tr class="categorie"><th colspan="4">Invitations envoyées en attente de réponse ($n)</th></tr>
        <tr><th>Identité</th><th>Nom affiché</th><th>Adresse électronique</th><th></th></tr>

FIN;
  while ( $r = $resultat->fetch_assoc() )
    ligne($r);
  $resultat->free();
}

// Décompte total des utilisateurs (comptes validés et non désactivés) en fonction de leur type
foreach ( $autorisations as $v => $a)  {
  $resultat = $mysqli->query(str_replace('XXX',"mdp > '0' AND autorisation%10=$v",$requete));
  if ( $n = $resultat->num_rows )  {
    echo <<<FIN
        <tr class="categorie"><th colspan="4">${a[0]} ($n)</th></tr>
        <tr><th>Identité</th><th>Nom affiché</th><th>Adresse électronique</th><th></th></tr>

FIN;
    while ( $r = $resultat->fetch_assoc() )
      ligne($r);
    $resultat->free();
  }
}

// Récupération des comptes désactivés
$resultat = $mysqli->query(str_replace('XXX','mdp LIKE \'!%\'',$requete));
if ( $n = $resultat->num_rows )  {
    echo <<<FIN
        <tr class="categorie"><th colspan="4">Comptes désactivés ($n)</th></tr>
        <tr><th>Identité</th><th>Nom affiché</th><th>Adresse électronique</th><th></th></tr>

FIN;
  while ( $r = $resultat->fetch_assoc() )
    ligne($r);
  $resultat->free();
}
?>
      </tbody>
    </table>
  </article>

<?php
// Aide et formulaire d'édition
if ( $edition )  {
?>
  <div id="form-edite">
    <a class="icon-ok" title="Valider ces modifications"></a>
    <h3 class="edition">Modifier un utilisateur</h3>
    <form>
      <p id="compteactif">Vous pouvez ici modifier le compte XXX, de type YYY. Ce compte est actif. L'utilisateur du compte ne sera pas automatiquement prévenu de vos modifications.</p>
      <p id="comptedesactive">Vous pouvez ici modifier le compte XXX, de type YYY. Ce compte a été désactivé&nbsp;: la connexion à ce Cahier de Prépa par ce compte n'est pas possible.</p>
      <p id="demande">Vous pouvez ici modifier la demande XXX, de type YYY. Cette demande n'a pas encore été validée, vous pourrez la valider après modification.</p>
      <p id="invitation">Vous pouvez ici modifier l'invitation XXX, de type YYY. L'utilisateur de ce compte ne sera pas automatiquement prévenu de vos modifications. Attention, la validation de l'invitation par l'utilisateur concerné ne sera plus possible.</p>
      <p>Seules les valeurs modifiées seront prises en compte. Pour modifier l'adresse électronique, il est nécessaire de la saisir deux fois. Il n'est pas possible de saisir une adresse électronique correspondant déjà à un autre compte. Un mail de confirmation sera envoyé à l'adresse saisie.</p>
      <p class="ligne"><label for="prenom">Prénom&nbsp;: </label><input type="text" name="prenom" value="" size="50"></p>
      <p class="ligne"><label for="nom">Nom&nbsp;: </label><input type="text" name="nom" value="" size="50"></p>
      <p class="ligne"><label for="mailexp">Nom affiché comme expéditeur/destinataire&nbsp;: </label><input type="text" name="mailexp" value="" size="50"></p>
      <p class="ligne"><label for="mail1">Adresse électronique&nbsp;: </label><input type="email" name="mail1" value="" size="50"></p>
      <p class="ligne"><label for="mail2">Confirmation (si modification)&nbsp;: </label><input type="email" name="mail2" value="" size="50"></p>
      <p class="ligne"><label for="mailcopie">Utilisateur recevant une copie des courriels envoyés&nbsp;: </label><input type="checkbox" name="mailcopie" value="1"></p>
    </form>
  </div>
  
  <div id="aide-utilisateurs">
    <h3>Aide et explications</h3>
    <p>Il est possible ici de régler les possibilités d'envoi de courriels, de visualiser l'ensemble des adresses électronique et de modifier les données des utilisateurs pouvant se connecter à ce Cahier de Prépa.</p>
    <p><strong>Cette page est visible par tous les utilisateurs ayant un compte de type professeur ou lycée, ou ayant les droits d'administration. Mais elle n'est modifiable que par les utilisateurs disposant des droits d'administration.</strong></p>
    <p>Les associations entre les utilisateurs et les matières sont à régler à la page de <a href="utilisateurs-matieres">gestion utilisateurs-matières</a>.</p>
    <p>L'ajout, la suppression et la désactivation des comptes utilisateurs est possible à la page de <a href="utilisateurs">gestion des utilisateurs</a>, accessible uniquement pour les utilisateurs disposant des droits d'adminstration. La modification de l'identifiant ou du type d'un compte est aussi modifiable sur cette même page.</p>
    <p>Le seul bouton général <span class="icon-download"></span> permet de récupérer l'ensemble des noms et adresses électroniques de tous les utilisateurs en fichier de type <code>xls</code>, éditable par un logiciel tableur (Excel, LibreOffice Calc...).</p>
    <h4>Possibilités d'envoi de courriels</h4>
    <p>Le tableau est une double correspondance où chaque échange peut être autorisé (<span class="icon-ok"></span>) ou interdit (<span class="icon-nok"></span>). Un clic sur un de ces boutons commute entre autorisation et interdiction.</p>
    <p>Les boutons <span class="icon-ok"></span> et <span class="icon-nok"></span> en début de ligne permettent de modifier d'un seul clic toutes les possibilités d'envoi de la ligne.</p>
    <p>L'action est immédiate et s'applique instantanément à tous les utilisateurs, même connectés.</p>
    <h4>Tableau récapitulatif</h4>
    <p>Le tableau général présente tous les utilisateurs existants, ordonnés par type puis par ordre alphabétique.</p>
    <p>La liste des comptes affichée peut être filtrée à l'aide des menus déroulants dans la barre de recherche située au-dessus du tableau.</p>
    <p>Les données caractéristiques des utilisateurs pour l'envoi de courriels (<em>nom</em>, <em>prénom</em>, <em>adresse électronique</em>, <em>nom affiché</em> comme expéditeur) sont modifiables en cliquant sur le bouton <span class="icon-edite"></span> qui ouvrira un formulaire.</p>
    <p>La colonne <em>identité</em> contient le nom et le prénom de l'utilisateur. Elle est utilisée pour ordonner la liste des utilisateurs visible dans les listes hors de l'envoi de courriels (notes de colles, transferts de documents). La colonne <em>nom affiché</em> contient le nom d'expéditeur/destinataire de l'utilisateur, qui sera le seul affiché dans la page d'envoi de courriels et dans les entêtes des courriels eux-mêmes. Par défaut, elle contient le prénom suivi du nom, mais on peut la modifier pour y mettre un prénom/nom d'usage ou par exemple «&nbsp;M.&nbsp;Bidule&nbsp;» pour un professeur, «&nbsp;Mme&nbsp;Machine (CPE)&nbsp;» pour une CPE.</p>
    <p>Les identifiants ne sont pas modifiables ici, mais sur la page de <a href="utilisateurs">gestion des utilisateurs</a>, accessible uniquement pour les utilisateurs disposant des droits d'adminstration.</p>
    <p>Chaque utilisateur peut aussi modifier lui-même toutes ces données dans sa page de préférences.</p>
  </div>

<?php
}
// Cas des non-administrateurs
else  {
?>
  <div id="aide-utilisateurs">
    <h3>Aide et explications</h3>
    <p>Il est possible ici de visualiser les possibilités d'envoi de courriels et l'ensemble des adresses électroniques des utilisateurs pouvant se connecter à ce Cahier de Prépa. Les utilisateurs disposant des droits d'administration ont la possibilité de modifier les réglages d'envoi et les comptes.</p>
    <p>Les associations entre les utilisateurs et les matières sont visibles à la page de <a href="utilisateurs-matieres">gestion utilisateurs-matières</a>.</p>
    <p>Le seul bouton général <span class="icon-download"></span> permet de récupérer l'ensemble des noms et adresses électroniques de tous les utilisateurs en fichier de type <code>xls</code>, éditable par un logiciel tableur (Excel, LibreOffice Calc...).</p>
    <h4>Possibilités d'envoi de courriels</h4>
    <p>Le tableau est une double correspondance où chaque échange peut être autorisé (<span class="icon-ok"></span>) ou interdit (<span class="icon-nok"></span>).</p>
    <h4>Tableau récapitulatif</h4>
    <p>Le tableau général présente tous les utilisateurs existants, ordonnés par type puis par ordre alphabétique.</p>
    <p>La liste des comptes affichée peut être filtrée à l'aide des menus déroulants dans la barre de recherche située au-dessus du tableau.</p>
    <p>La colonne <em>identité</em> contient le nom et le prénom de l'utilisateur. Elle est utilisée pour ordonner la liste des utilisateurs visible dans les listes hors de l'envoi de courriels (notes de colles, transferts de documents). La colonne <em>nom affiché</em> contient le nom d'expéditeur/destinataire de l'utilisateur, qui sera le seul affiché dans la page d'envoi de courriels et dans les entêtes des courriels eux-mêmes. Par défaut, elle contient le prénom suivi du nom, mais on peut la modifier pour y mettre un prénom/nom d'usage ou par exemple «&nbsp;M.&nbsp;Bidule&nbsp;» pour un professeur, «&nbsp;Mme&nbsp;Machine (CPE)&nbsp;» pour une CPE.</p>
    <p>Chaque utilisateur peut aussi modifier lui-même toutes ces données dans sa page de préférences.</p>
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
