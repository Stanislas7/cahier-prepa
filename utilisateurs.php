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
// Remarque : ASCII -> !=33, *=52, 0=60, ?=63 

//////////////////
// Autorisation //
//////////////////

// Accès aux administrateurs uniquement. Redirection spéciale pour les colleurs/lycée/profs.
if ( $autorisation && !$_SESSION['admin'] )  {
  header( ( $autorisation > 3 ) ? "Location: https://$domaine${chemin}utilisateurs-mails" : "Location: https://$domaine$chemin");
  exit();
}
$mysqli = connectsql();
// Si non connecté, demande de connexion
// Si connexion light : on doit répéter son mot de passe pour aller plus loin
// login.php contient fin()
if ( !$autorisation || $_SESSION['light'] )  {
  $titre = 'Gestion des utilisateurs';
  $actuel = 'utilisateurs';
  include('login.php');
}

////////////////////////////////////////////////////////////
// Exportation des utilisateurs en xls : Version complète //
////////////////////////////////////////////////////////////
// Version allégée, pour les lycée/profs non admins, dans utilisateurs-mails.php
// Exportation uniquement si aucun header déjà envoyé
if ( isset($_REQUEST['xls']) && !headers_sent() )  {
  // Recherche des utilisateurs concernés : comptes validés hors comptes invités
  // et hors comptes sans nom et sans mail (identifiant seul)
  $resultat = $mysqli->query('SELECT u.nom, prenom, IF(LENGTH(mail),mail,"Pas d\'adresse") AS mail, autorisation%10 AS autorisation,
                              autorisation > 10 AS admin, GROUP_CONCAT(m.nom ORDER BY m.ordre SEPARATOR \', \') AS mats 
                              FROM utilisateurs AS u JOIN matieres AS m ON FIND_IN_SET(m.id,u.matieres)
                              WHERE mdp > \'0\' AND autorisation > 1 AND u.nom > \'\' OR prenom > \'\' OR mail != \'\'
                              GROUP BY u.id ORDER BY autorisation DESC, IF(LENGTH(u.nom),CONCAT(u.nom,prenom),login)');
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
    saisie_chaine(0, 4, utf8_decode('Administrateur'));
    saisie_chaine(0, 5, utf8_decode('Matières'));
    $i = 0;
    while ( $r = $resultat->fetch_assoc() )  {
      saisie_chaine(++$i, 0, utf8_decode($r['nom']));
      saisie_chaine($i, 1, utf8_decode($r['prenom']));
      saisie_chaine($i, 2, utf8_decode($r['mail']));
      saisie_chaine($i, 3, utf8_decode($categories[$r['autorisation']]));
      saisie_chaine($i, 4, $r['admin'] ? 'Oui' : 'Non');
      saisie_chaine($i, 5, utf8_decode($r['mats']));
    }
    // Fin du fichier xls
    echo pack("ss", 0x0A, 0x00);
    $resultat->free();
  }
  exit();
}

// Limitation de la recherche
$requete = 'WHERE XXX'; 

// Types de comptes
$autorisations = array(5=>'Professeur',4=>'Lycée',3=>'Colleur',2=>'Élève',1=>'Invité');
$autorisations_cle = array(5=>'profs',4=>'lycee',3=>'colleurs',2=>'eleves',1=>'invites');
$select_types = '';
foreach ( $autorisations as $v => $a )  {
  $cle = $autorisations_cle[$v];
  $select_types .= "          <option value=\"$cle\">$a</option>\n";
  if ( isset($_REQUEST['type']) && ( $cle == $_REQUEST['type'] ) )  {
    $cle_type = $cle;
    $requete = "WHERE autorisation%10 = $v AND XXX";
  }
}

// Récupération des matières
// Remarque : n'apparaissent pas les profs-colleurs lorsqu'on demande une matière
// où ils sont colleurs. Trop complexe et pas assez fréquent pour être utile
$resultat = $mysqli->query('SELECT id, nom, cle FROM matieres ORDER BY ordre');
$select_matieres_filtre = $select_matieres_ajout = '';
while ( $r = $resultat->fetch_assoc() )  {
  $select_matieres_filtre .= "      <option value=\"${r['cle']}\">${r['nom']}</option>\n";
  $select_matieres_ajout .= "      <option value=\"${r['id']}\">${r['nom']}</option>\n";
  if ( isset($_REQUEST['matiere']) && ( $r['cle'] == $_REQUEST['matiere'] ) )  {
    $cle_matiere = $r['cle'];
    $requete = "JOIN matieres AS m ON FIND_IN_SET(m.id,u.matieres) $requete AND m.id = ${r['id']}";
  }
}
$resultat->free();

// Requête
$requete = "SELECT u.id, u.nom, prenom, login, autorisation%10 AS autorisation, autorisation > 10 AS admin FROM utilisateurs AS u $requete";

// Ordre de visualisation
$requete .= ( isset($_REQUEST['ordre']) && ( $_REQUEST['ordre'] == 'nom' ) ) ? ' ORDER BY u.nom, prenom, login' : ' ORDER BY autorisation%10 DESC, u.nom, prenom, login';

// Récupération de la préférence de création de compte
$resultat = $mysqli->query('SELECT IF(val,\' checked\',\'\') FROM prefs WHERE nom = "creation_compte"');
$creation_compte = $resultat->fetch_row()[0];
$resultat->free();
$resultat = $mysqli->query('SELECT COUNT(*) FROM utilisateurs WHERE mdp > \'0\'');
$n1 = $resultat->fetch_row()[0];
$resultat->free();
$resultat = $mysqli->query('SELECT COUNT(*) FROM utilisateurs WHERE autorisation > 10 AND mdp > \'0\'');
$n2 = $resultat->fetch_row()[0];
$resultat->free();
$resultat = $mysqli->query('SELECT COUNT(*) FROM utilisateurs where autorisation = 2 AND mdp >= \'*\'');
$n3 = $resultat->fetch_row()[0];
$resultat->free();
$textecomptes = "<p class=\"ligne\">Parmi les $n1 comptes actifs inscrits sur ce Cahier, ".( $n2>1 ? "$n2 disposent" : 'un seul dispose').' des droits d\'administration. Ceci est modifiable individuellement ci-dessous à l\'aide de l\'icône <span class="icon-edite"></span>.</p>';
if ( $n3 )
  $textecomptes .= '<p class="ligne">Il y a '.( $n3>1 ? "$n3 élèves inscrits" : 'un seul élève inscrit').' sur ce Cahier.</p>';
else
  $textecomptes .= '<p class="ligne">Il n\'y a pas d\'élèves inscrits sur ce Cahier.</p>';

//////////////
//// HTML ////
//////////////
// La data-action sert exceptionnellement au format CSS pour les pages de moins de 500 pixels de large
debut($mysqli,'Modification des utilisateurs',$message,$autorisation,'utilisateurs',array('action'=>'utilisateurs'));
?>

  <div id="icones">
    <a class="icon-ajoute formulaire" title="Ajouter de nouveaux utilisateurs"></a>
    <a class="icon-download" href="?xls" title="Télécharger la liste des utilisateurs en xls"></a>
    <a class="icon-aide" title="Aide pour les modifications des utilisateurs"></a>
  </div>

  <article data-action="creationcompte">
    <a class="icon-aide" title="Aide"></a>
    <h3 class="edition">Réglages de la gestion des comptes</h3>
    <p>Il est possible d'autoriser ou non des personnes non connectées à demander un compte. Cela n'est possible que pour les comptes élèves. Lorsqu'une demande est réalisée, elle doit être validée par un administrateur du Cahier.</p>
    <p class="ligne"><label for="creation_compte">Autoriser les demandes de création de comptes élèves&nbsp;: </label>
      <input type="checkbox" id="creation_compte"<?php echo $creation_compte; ?>>
    </p>
  </article>

  <p id="rechercheutilisateurs" class="topbarre">
    <select id="type" onchange="window.location='?type='+this.value+'&amp;matiere='+$('#matiere').val()+'&amp;ordre='+$('#ordre').val();">
      <option value="tout">Filtrer par type</option>
<?php echo( isset($cle_type) ? str_replace("\"$cle_type\"","\"$cle_type\" selected",$select_types) : $select_types ); ?>
    </select>
    <select id="matiere" onchange="window.location='?type='+$('#type').val()+'&amp;matiere='+this.value+'&amp;ordre='+$('#ordre').val();">
      <option value="tout">Filtrer par matière</option>
<?php echo( isset($cle_matiere) ? str_replace("\"$cle_matiere\"","\"$cle_matiere\" selected",$select_matieres_filtre) : $select_matieres_filtre ); ?>
    </select>
    <span class="icon-recherche" onclick="$(this).next().val('').change();"></span>
    <input type="text" value="" placeholder="Rechercher un nom, prénom...">
  </p>

  <article data-action="tableau">
    <a class="icon-aide" title="Aide"></a>
    <h3>Liste des utilisateurs</h3>
    <?php echo $textecomptes; ?>
    <table id="u" class="utilisateurs">
      <tbody>
<?php

// Fonction d'affichage des lignes du tableau
// $r : les données utilisateurs
// $type : entier correspondant au type de compte : 1->demandes à valider,
// 2->invitations non répondues, 3->comptes classiques, 4->comptes désactivés
function ligne($r,$type)  {
  $autorisation = $GLOBALS['autorisations'][$r['autorisation']] . ( intval($r['admin']) ? ' (administrateur)' : '' );
  switch ($type)  {
    case 1:
      $deuxiemeicone = '<a class="icon-validutilisateur" title="Valider cette demande"></a>';
      $texte = 'cette demande';
      break;
    case 2:
      $deuxiemeicone = '<a class="icon-renvoiinvite" title="Renvoyer l\'invitation"></a>';
      $texte = 'cette invitation';
      break;
    case 3:
      $deuxiemeicone = '<a class="icon-desactive" title="Désactiver ce compte"></a>';
      $texte = 'ce compte';
      break;
    case 4:
      $deuxiemeicone = '<a class="icon-active" title="Réactiver ce compte"></a>';
      $texte = 'ce compte';
  }
  echo <<<FIN
        <tr data-id="${r['id']}">
          <td>${r['nom']}</td>
          <td>${r['prenom']}</td>
          <td>${r['login']}</td>
          <td>$autorisation</td>
          <td class="icones">
            <a class="icon-edite" title="Éditer $texte"></a>
            $deuxiemeicone
            <a class="icon-supprutilisateur" title="Supprimer $texte"></a>
          </td>
          <td class="icones"><input type="checkbox"></td>
        </tr>

FIN;
}

// Ligne commune
$premiereligne = '<th>Nom&nbsp;<span class="icon-deplie ordre_nom"></span></th><th>Prénom</th><th>Identifiant</th><th>Type&nbsp;<span class="icon-deplie ordre_type"></span></th>';

// Récupération des demandes à valider
$resultat = $mysqli->query(str_replace('XXX','mdp LIKE \'*%\'',$requete));
if ( $n = $resultat->num_rows )  {
  echo  <<<FIN
        <tr class="categorie"><th colspan="6">Demandes en attente de validation ($n)</th></tr>
        <tr>
          $premiereligne
          <th class="icones">
            <span> </span>
            <a class="icon-validutilisateur" title="Valider toutes les demandes cochées"></a>
            <a class="icon-supprutilisateur" title="Supprimer toutes les demandes cochées"></a>
          </th>
          <th class="icones"><a class="icon-cocher" title="Tout cocher"></a></th>
        </tr>

FIN;
  while ( $r = $resultat->fetch_assoc() )
    ligne($r,1);
  $resultat->free();
}

// Récupération des invitations non répondues
$resultat = $mysqli->query(str_replace('XXX','mdp = \'?\'',$requete));
if ( $n = $resultat->num_rows )  {
  echo <<<FIN
        <tr class="categorie"><th colspan="6">Invitations envoyées en attente de réponse ($n)</th></tr>
        <tr>
          $premiereligne
          <th class="icones">
            <span> </span>
            <a class="icon-renvoiinvite" title="Renvoyer une invitation à tous les comptes cochés"></a>
            <a class="icon-supprutilisateur" title="Supprimer toutes les invitations cochées"></a>
          </th>
          <th class="icones"><a class="icon-cocher" title="Tout cocher"></a></th>
        </tr>

FIN;
  while ( $r = $resultat->fetch_assoc() )
    ligne($r,2);
  $resultat->free();
}

// Décompte total des utilisateurs (comptes validés et non désactivés) en fonction de leur type
$resultat = $mysqli->query(str_replace('XXX','mdp > "0"',$requete));
if ( $n = $resultat->num_rows )  {
  echo <<<FIN
        <tr class="categorie"><th colspan="6">Comptes actuellement actifs ($n)</th></tr>
        <tr>
          $premiereligne
          <th class="icones">
            <span> </span>
            <a class="icon-desactive" title="Désactiver tous les comptes cochés"></a>
            <a class="icon-supprutilisateur" title="Supprimer tous les comptes cochés"></a>
          </th>
          <th class="icones"><a class="icon-cocher" title="Tout cocher"></a></th>
        </tr>

FIN;
  while ( $r = $resultat->fetch_assoc() )
    ligne($r,3);
  $resultat->free();
}

// Récupération des comptes désactivés
$resultat = $mysqli->query(str_replace('XXX','mdp LIKE \'!%\'',$requete));
$mysqli->close();
if ( $n = $resultat->num_rows )  {
  echo <<<FIN
        <tr class="categorie"><th colspan="6">Comptes désactivés ($n)</th></tr>
        <tr>
          $premiereligne
          <th class="icones">
            <span> </span>
            <a class="icon-active" title="Activer tous les comptes cochés"></a>
            <a class="icon-supprutilisateur" title="Supprimer tous les comptes cochés"></a>
          </th>
          <th class="icones"><a class="icon-cocher" title="Tout cocher"></a></th>
        </tr>

FIN;
  while ( $r = $resultat->fetch_assoc() )
    ligne($r,4);
  $resultat->free();
}
?>
      </tbody>
    </table>
  </article>

  <form id="form-ajoute" data-action="ajout-utilisateurs">
    <h3 class="edition">Ajouter de nouveaux utilisateurs</h3>
    <p class="ligne"><label for="autorisation">Type de comptes&nbsp;:</label>
      <select name="autorisation">
        <option selected hidden value="0">Choisir ...</option>
        <option value="1">Invités</option>
        <option value="2">Élèves</option>
        <option value="3">Colleurs</option>
        <option value="4">Lycée</option>
        <option value="5">Professeurs</option>
      </select>
    </p>
    <p class="ligne"><label for="matieres">Matières associées&nbsp;:</label>
      <select multiple name="matieres[]">
        <option value="0">Pas de matière associée</option>
<?php echo $select_matieres_ajout; ?>
      </select>
    </p>
    <p class="ligne"><label for="admin">Droits d'administration du Cahier&nbsp;: </label>
      <input type="checkbox" name="admin" value="1">
    </p>
    <p class="ligne"><label for="saisie">Type de saisie&nbsp;:</label>
      <select name="saisie">
        <option selected value="1">Invitation électronique</option>
        <option value="2">Saisie du mot de passe</option>
      </select>
    </p>
    <p class="ligne"><label for="ordre">Ordre&nbsp;:</label>
      <select name="ordre">
        <option selected value="1">Nom, Prénom</option>
        <option value="2">Prénom, Nom</option>
      </select>
    </p>
    <p class="ligne"><strong>Comptes à créer&nbsp;:</strong></p>
    <p>Écrire ci-dessous les nouveaux utilisateurs&nbsp;:</p>
    <ul>
      <li>Un utilisateur par ligne<span class="affichesinoninvite">, <strong>le <span class="ordre1">nom</span><span class="ordre2">prénom</span> en premier</strong></span></li>
      <li>Uniquement des utilisateurs de même type associés aux mêmes matières</li>
    </ul>
    <p class="annonce affichesiinvitation">Format&nbsp;:&nbsp;<span class="ordre">nom,prénom</span>,adresse</p>
    <p class="annonce affichesimotdepasse">Format&nbsp;:&nbsp;<span class="ordre">nom,prénom</span>,mot-de-passe</p>
    <textarea name="listeutilisateurs" rows="10" cols="100"></textarea>
    <p class="affichesiinvitation">Sur chaque ligne, vous devez écrire nom, prénom, adresse électronique (séparés par des virgules). Un courriel sera envoyé à chaque utilisateur avec un lien à validité permanente pour saisir le mot de passe. Ces comptes apparaîtront dans la catégorie «&nbsp;invitation&nbsp;» tant qu'ils n'auront pas finalisé leur inscription.</p>
    <p class="affichesiinvitation eleves"><strong>Cette fonctionnalité est prévue avant tout pour les colleurs et les collègues</strong>. Pour les élèves, elle risque fortement de vous faire écrire de mauvaises adresses, notamment si vous recopiez des adresses manuscrites. Cela conduit à dégrader la vision qu'ont les grands serveurs de courriel de ce site, et le risque que les prochains courriels soient classés comme <em>spam</em> pourrait augmenter.</p>
    <p class="affichesiinvitation eleves"><strong>Il est donc recommandé de demander aux élèves de demander une création de compte</strong>, en passant par le panneau de connexion et en suivant les étapes. L'adresse électronique sera ainsi automatiquement validée.</p>
    <p class="affichesimotdepasse">Sur chaque ligne, vous devez écrire nom, prénom et mot de passe (séparés par des virgules). Les utilisateurs ne seront pas prévenus automatiquement de cette création de compte, ce sera à vous de le faire. Ils pourront modifier leur mot de passe s'ils le souhaitent. Ils ne pourront envoyer des courriels que s'ils saisissent une adresse électronique.</p>
    <p class="affichesiinvite">Sur chaque ligne, vous devez écrire l'identifiant du compte et le mot de passe, séparés par une virgule. Vous pourrez ensuite communiquer ces coordonnées aux personnes concernées. Elles ne pourront pas modifier le mot de passe que vous avez choisi.</p>
  </form>

  <div id="form-edite">
    <a class="icon-ok" title="Valider ces modifications"></a>
    <h3 class="edition">Modifier un utilisateur</h3>
    <form>
      <p id="compteactif">Vous pouvez ici modifier le compte XXX, de type YYY. Ce compte est actif. L'utilisateur du compte ne sera pas automatiquement prévenu de vos modifications.</p>
      <p id="comptedesactive">Vous pouvez ici modifier le compte XXX, de type YYY. Ce compte a été désactivé&nbsp;: la connexion à ce Cahier de Prépa par ce compte n'est pas possible.</p>
      <p id="demande">Vous pouvez ici modifier la demande XXX, de type YYY. Cette demande n'a pas encore été validée, vous pourrez la valider après modification.</p>
      <p id="invitation">Vous pouvez ici modifier l'invitation XXX, de type YYY. L'utilisateur de ce compte ne sera pas automatiquement prévenu de vos modifications. La validation de l'invitation reste possible et nécessaire, avec le lien déjà envoyé par courriel.</p>
      <p>Seules les valeurs modifiées seront prises en compte.</p>
      <p>Attention, modifier le nom ici ne change pas l'affichage de l'utilisateur dans la liste des adresses électronique. Vous devez aussi aller sur la page de <a href="utilisateurs-mails">gestion des courriels</a> pour y modifier le «&nbsp;nom d'expéditeur&nbsp;».</p>
      <p class="ligne"><label for="prenom">Prénom&nbsp;: </label><input type="text" name="prenom" value="" size="50"></p>
      <p class="ligne"><label for="nom">Nom&nbsp;: </label><input type="text" name="nom" value="" size="50"></p>
      <p class="ligne"><label for="login">Identifiant&nbsp;: </label><input type="text" name="login" value="" size="50"></p>
      <hr>
      <p>Il est possible, si besoin, de modifier le type de compte (sous réserve qu'il n'y ait pas d'impossibilité avec des notes de colles et des transferts de documents). Ce compte <span class="admin0">ne possède pas</span><span class="admin1">possède</span> les droits d'administration du Cahier.</p>
      <p class="ligne"><label for="autorisation">Type de compte&nbsp;:</label><select name="autorisation">
        <option value="2">Élève</option>
        <option value="3">Colleur</option>
        <option value="4">Lycée</option>
        <option value="5">Professeur</option>
      </select></p>
      <p class="ligne"><label for="admin">Droits d'administration du Cahier&nbsp;: </label>
        <input type="checkbox" name="admin" value="1">
      </p>
      <p>Les droits d'administration permettent à l'utilisateur de tout modifier, exactement comme vous pouvez le faire. Il est préférable que seuls les «&nbsp;principaux&nbsp;» professeurs de la classe aient ces droits. Pour chaque matière, les professeurs associés restent capables de modifier tout ce qui en est relatif.</p>
    </form>
  </div>

  <div id="aide-utilisateurs">
    <h3>Aide et explications</h3>
    <p>Il est possible ici d'ajouter, de modifier, de désactiver et de supprimer des comptes utilisateurs pouvant se connecter à ce Cahier de Prépa.</p>
    <p><strong>Cette page n'est accessible que pour les comptes disposant des <em>droits d'administration</em>.</strong></p>
    <p>Les associations entre les utilisateurs et les matières sont à régler à la page de <a href="utilisateurs-matieres">gestion utilisateurs-matières</a>.</p>
    <p>Les adresses électroniques et les noms-prénoms d'expéditeur de courriel sont modifiables sur la page de <a href="utilisateurs-mails">gestion des courriels</a>.</p>
    <p>Les deux boutons généraux permettent de&nbsp;:</p>
    <ul>
      <li><span class="icon-ajoute"></span>&nbsp;: ouvrir un formulaire pour ajouter de nouveaux utilisateurs.</li>
      <li><span class="icon-download"></span>&nbsp;: récupérer l'ensemble des noms et adresses électroniques de tous les utilisateurs en fichier de type <code>xls</code>, éditable par un logiciel tableur (Excel, LibreOffice Calc...).</li>
    </ul>
    <h4>Tableau récapitulatif</h4>
    <p>Le tableau général présente tous les comptes utilisateurs existants et permet de les modifier, désactiver ou supprimer. Voir l'aide spécifique du tableau pour plus de détails.</p>
    <h4>Types d'utilisateurs</h4>
    <p>Chaque compte utilisateur a un <em>type</em> qui lui donne certains droits et modifie l'affichage de certaines pages. Chaque compte peut être associé ou non à une ou plusieurs matières&nbsp;: l'utilisateur ne verra pas les contenus correspondant aux matières auxquelles il n'est pas associé.</p>
    <p>Il existe cinq types de comptes utilisateurs&nbsp;:</p>
    <ul>
      <li>Les <em>professeurs</em> peuvent modifier/ajouter/supprimer tous les contenus associés à leur(s) matière(s) ou à la matière «&nbsp;Général&nbsp;»&nbsp;: pages d'informations, documents, programmes de colles, cahiers de textes, notes de colles, transferts de documents et agenda. Tous les professeurs associés à une matière y ont les mêmes droits. Tous les professeurs ont les mêmes droits sur les pages d'informations générales et les documents généraux. Le fait de pouvoir administrer globalement le Cahier est découplé du type de compte et nécessite les <em>droits d'administration</em>.</li>
      <li>Les utilisateurs liés à l'administration du <em>lycée</em> peuvent relever les déclarations de colles, voir des statistiques matière par matière et colleur par colleur. Il n'est pas nécessaire d'associer ce type de compte avec toutes les matières.</li>
      <li>Les <em>colleurs</em> peuvent être associés ou non à une ou plusieurs matières. Ils peuvent voir les contenus associés à ces matières et les contenus généraux, si l'accès de ces contenus est autorisé. Ils peuvent mettre des notes dans ces matières et voir leurs notes uniquement. Ils peuvent utiliser les transferts de documents.</li>
      <li>Les <em>élèves</em> peuvent être associés ou non à une ou plusieurs matières. Ils peuvent voir les contenus associés à ces matières et les contenus généraux, si l'accès de ces contenus est autorisé. Ils peuvent voir leurs notes de colles et leurs documents transférés.</li>
      <li>Les <em>invités</em> sont des comptes prévus pour être éventuellement partagés entre plusieurs personnes. Une fois connecté, il est impossible de changer les paramètres du compte (identifiant, mot de passe, matières associées). Les invités peuvent voir les contenus associés à leurs matières et les contenus généraux, si l'accès de ces contenus est autorisé. Il est impossible d'associer une adresse électronique à un compte invité.</li>
    </ul>
    <p>Chaque ressource (information, document) est accessible ou non à chaque type de compte. Cela se décide indépendamment et est modifiable sans restriction par tout professeur associé à la matière.</p>
    <p>Il est possible sur cette page de changer le type d'un compte utilisateur à tout moment, pourvu que cela ne pose pas de problème par rapport aux données déjà saisies (notes de colles et transferts de documents&nbsp;: impossible par exemple de transformer un compte élève qui aurait déjà reçu des notes en compte colleur).</p>
    <p>Tous les utilisateurs (sauf comptes invités) peuvent modifier leur identité et/ou leur mot de passe. Pour la modification d'une adresse électronique, une confirmation est envoyée par courriel pour éviter les erreurs.</p>
    <p>Tous les utilisateurs (sauf comptes invités) peuvent, s'ils ont renseigné une adresse électronique, envoyer des courriels. Mais certains envois peuvent être autorisés ou non par les comptes administrateurs, sur la page des <a href="reglages">réglages généraux</a> du Cahier.</p>
    <h4>Droits d'administration</h4>
    <p>Chaque compte utilisateur de type <em>professeur</em>, <em>lycée</em> ou <em>colleur</em> peut disposer des <em>droits d'administration</em>. Cela permet de pouvoir tout régler au sein du Cahier, comme vous pouvez le faire avec le compte que vous utilisez actuellement.</p>
    <p>Il est possible de tout casser avec des <em>droits d'administration</em>, mais heureusement, ce n'est pas facile et l'ensemble des actions sont réversibles en demandant gentiment de l'aide à l'administrateur du site. Dans le doute, il vaut quand-même mieux éviter de donner ces droits à un(e) collègue qui n'est vraiment pas à l'aise avec l'outil informatique, et/ou qui ne participe pas à la gestion du Cahier.</p>
    <p>Tout administrateur peut enlever les <em>droits d'administration</em> à tous les autres. La seule limite est qu'un compte au minimum dispose de ces droits.</p>
  </div>
  
  <div id="aide-tableau">
    <h3>Aide et explications</h3>
    <p>Il est possible dans ce tableau de modifier, de désactiver ou de supprimer des comptes utilisateurs pouvant se connecter à ce Cahier de Prépa.</p>
    <p>Ce tableau présente tous les comptes utilisateurs existants, par défaut ordonnés par type puis par ordre alphabétique.</p>
    <p>La liste des comptes affichée peut être filtrée à l'aide des menus déroulants dans la barre de recherche située au-dessus du tableau.</p>
    <p>Sur chaque ligne, il est possible de cliquer sur les boutons&nbsp;:</p>
      <ul>
        <li><span class="icon-edite"></span> pour modifier les données d'identification de l'utilisateur à l'aide d'un nouveau formulaire.</li>
        <li><span class="icon-desactive"></span> pour désactiver le compte de l'utilisateur, après confirmation.</li>
        <li><span class="icon-supprutilisateur"></span> pour supprimer le compte de l'utilisateur, après confirmation.</li>
      </ul>
    <h4>Modification</h4>
    <p>Chaque compte utilisateur peut être modifié en cliquant sur le bouton <span class="icon-edite"></span>. Un nouveau formulaire s'ouvre. Il est possible d'y modifier les données d'identification du compte (nom, prénom, identifiant), le type de compte, et le fait qu'il ait les <em>droits d'administation</em> ou non.</p>
    <p>L'identifiant ne sert qu'à la connexion de l'utilisateur&nbsp;: n'oubliez pas de le prévenir si vous modifiez l'identifiant. Il pourra néanmoins se connecter à l'aide de son adresse électronique.</p>
    <p>Modifier le nom et le prénom reclasse l'utilisateur dans les listes mais ne change pas l'affichage de l'utilisateur dans la liste des adresses électronique, où l'on voit le «&nbsp;nom d'expéditeur&nbsp;». Vous devez aussi aller sur la page de <a href="utilisateurs-mails">gestion des courriels</a> pour modifier cette caractéristique.</p>
    <p>La modification du type de compte est possible à tout moment, pourvu que cela ne pose pas de problème par rapport aux données déjà saisies (notes de colles et transferts de documents&nbsp;: impossible par exemple de transformer un compte élève qui aurait déjà reçu des notes en compte colleur).</p>
    <h4>Suppression et désactivation</h4>
    <p>Chaque compte utilisateur peut être supprimé en cliquant sur le bouton <span class="icon-supprutilisateur"></span> (une confirmation sera demandée). Attention, la suppression d'un compte utilisateur (élève, colleur, professeur) entraîne automatiquement et définitivement la suppression des notes de colles et des documents transférés le concernant. <strong>Ne supprimez surtout pas un compte pour le recréer</strong>, modifiez-le directement.</p>
    <p>Chaque compte utilisateur peut être désactivé en cliquant sur le bouton <span class="icon-desactive"></span> (une confirmation sera demandée)&nbsp;: cela permet de supprimer la possibilité de l'utilisateur de se connecter tout en conservant ses données comme son adresse électronique et les colles effectuées. C'est donc l'opération à réaliser pour un élève (ou un colleur) parti en cours d'année, dont on veut conserver les notes de colles jusqu'en fin d'année. Cette opération est réversible autant de fois qu'on le souhaite. Les comptes utilisateurs désactivés sont listés en bas du tableau et on peut réactiver un compte en cliquant sur le bouton <span class="icon-active"></span>.</p>
    <h4>Modifications multiples</h4>
    <p>Il est possible de réaliser une action identique (suppression ou désactivation) sur plusieurs comptes utilisateurs simultanément en cochant les cases en bout de ligne et en cliquant sur les boutons d'action situés sur les lignes d'entêtes. Le bouton <span class="icon-cocher"></span> permet de cocher tous les comptes. Le filtrage sur le type de compte peut permettre une action simultanée par exemple sur tous les comptes élèves.</p>
    <h4>Types d'utilisateurs</h4>
    <p>Chaque compte utilisateur a un <em>type</em> qui lui donne certains droits et modifie l'affichage de certaines pages. Chaque compte peut être associé ou non à une ou plusieurs matières&nbsp;: l'utilisateur ne verra pas les contenus correspondant aux matières auxquelles il n'est pas associé.</p>
    <p>Il existe cinq types de comptes utilisateurs&nbsp;:</p>
    <ul>
      <li>Les <em>professeurs</em> peuvent modifier/ajouter/supprimer tous les contenus associés à leur(s) matière(s) ou à la matière «&nbsp;Général&nbsp;»&nbsp;: pages d'informations, documents, programmes de colles, cahiers de textes, notes de colles, transferts de documents et agenda. Tous les professeurs associés à une matière y ont les mêmes droits. Tous les professeurs ont les mêmes droits sur les pages d'informations générales et les documents généraux. Le fait de pouvoir administrer globalement le Cahier est découplé du type de compte et nécessite les <em>droits d'administration</em>.</li>
      <li>Les utilisateurs liés à l'administration du <em>lycée</em> peuvent relever les déclarations de colles, voir des statistiques matière par matière et colleur par colleur. Il n'est pas nécessaire d'associer ce type de compte avec toutes les matières.</li>
      <li>Les <em>colleurs</em> peuvent être associés ou non à une ou plusieurs matières. Ils peuvent voir les contenus associés à ces matières et les contenus généraux, si l'accès de ces contenus est autorisé. Ils peuvent mettre des notes dans ces matières et voir leurs notes uniquement. Ils peuvent utiliser les transferts de documents.</li>
      <li>Les <em>élèves</em> peuvent être associés ou non à une ou plusieurs matières. Ils peuvent voir les contenus associés à ces matières et les contenus généraux, si l'accès de ces contenus est autorisé. Ils peuvent voir leurs notes de colles et leurs documents transférés.</li>
      <li>Les <em>invités</em> sont des comptes prévus pour être éventuellement partagés entre plusieurs personnes. Une fois connecté, il est impossible de changer les paramètres du compte (identifiant, mot de passe, matières associées). Les invités peuvent voir les contenus associés à leurs matières et les contenus généraux, si l'accès de ces contenus est autorisé. Il est impossible d'associer une adresse électronique à un compte invité.</li>
    </ul>
    <p>Chaque ressource (information, document) est accessible ou non à chaque type de compte. Cela se décide indépendamment et est modifiable sans restriction par tout professeur associé à la matière.</p>
    <p>Il est possible sur cette page de changer le type d'un compte utilisateur à tout moment, pourvu que cela ne pose pas de problème par rapport aux données déjà saisies (notes de colles et transferts de documents&nbsp;: impossible par exemple de transformer un compte élève qui aurait déjà reçu des notes en compte colleur).</p>
    <p>Tous les utilisateurs (sauf comptes invités) peuvent modifier leur identité et/ou leur mot de passe. Pour la modification d'une adresse électronique, une confirmation est envoyée par courriel pour éviter les erreurs.</p>
    <p>Tous les utilisateurs (sauf comptes invités) peuvent, s'ils ont renseigné une adresse électronique, envoyer des courriels. Mais certains envois peuvent être autorisés ou non par les comptes administrateurs, sur la page des <a href="reglages">réglages généraux</a> du Cahier.</p>
    <h4>Droits d'administration</h4>
    <p>Chaque compte utilisateur de type <em>professeur</em>, <em>lycée</em> ou <em>colleur</em> peut disposer des <em>droits d'administration</em>. Cela permet de pouvoir tout régler au sein du Cahier, comme vous pouvez le faire avec le compte que vous utilisez actuellement.</p>
    <p>Il est possible de tout casser avec des <em>droits d'administration</em>, mais heureusement, ce n'est pas facile et l'ensemble des actions sont réversibles en demandant gentiment de l'aide à l'administrateur du site. Dans le doute, il vaut quand-même mieux éviter de donner ces droits à un(e) collègue qui n'est vraiment pas à l'aise avec l'outil informatique, et/ou qui ne participe pas à la gestion du Cahier.</p>
    <p>Tout administrateur peut enlever les <em>droits d'administration</em> à tous les autres. La seule limite est qu'un compte au minimum dispose de ces droits.</p>
  </div>

  <div id="aide-ajoute">
    <h3>Aide et explications</h3>
    <p>Ce formulaire permet de créer de nouveaux comptes utilisateurs. Il sera validé par un clic sur <span class="icon-ok"></span>, et abandonné (donc supprimé) par un clic sur <span class="icon-ferme"></span>.</p>
    <p>Il est possible de créer simultanément autant de comptes que l'on le souhaite, mais tous les comptes créés simultanément doivent correspondre à un même type d'utilisateurs (invités, élèves, colleurs, administratifs, professeurs).</p>
    <h4>Matières</h4>
    <p>Il est nécessaire de spécifier à quelle matières seront associés les utilisateurs (les mêmes pour les comptes créés en même temps). Toutes les matières peuvent être sélectionnées. Les ressources associées aux matières non associées seront interdites sans autre condition à ces nouveaux utilisateurs. Pour les colleurs et professeurs, les actions possibles seront restreintes aux matières associées. Pour les administratifs, la relève des colles est indépendante et se fait simultanément sur toutes les matières.</p>
    <p>L'association à une matière n'est pas définitive, il est possible de modifier (ajouter ou supprimer) les associations entre matières et utilisateurs à tout moment à la <a href="utilisateurs-matieres">gestion utilisateurs-matières</a>.</p>
    <p>Il est plus facile de <a href="matieres">créer les matières</a> avant de créer les comptes utilisateurs.</p>
    <h4>Invitation ou saisie du mot de passe</h4>
    <p>Deux méthodes d'inscription sont possibles&nbsp;:</p>
    <ul>
      <li>l'envoi d'invitation&nbsp;: il faut pour cela renseigner sur chaque ligne de la case de saisie nom, prénom et adresse électronique. Les utilisateurs recevront chacun un courriel avec un lien à usage unique et validité illimitée permettant de finaliser l'inscription en saisissant un mot de passe.</li>
      <li>la saisie directe du mot de passe&nbsp;: il faut pour cela renseigner sur chaque ligne de la case de saisie nom, prénom et mot de passe. Vous devrez alors contacter et donner le mot de passe choisi à chaque nouvel utilisateur. Cette méthode est moins bonne sur le plan de la sécurité.</li>
    </ul>
    <p>Les données à saisir doivent séparées par des virgules, sans espaces, et à un compte par ligne.</p>
    <p>Les élèves peuvent aussi s'inscrire seule en faisant une demande, disponible au niveau de l'identification.</p>
    <h4>Types d'utilisateurs</h4>
    <p>Chaque compte utilisateur a un <em>type</em> qui lui donne certains droits et modifie l'affichage de certaines pages. Chaque compte peut être associé ou non à une ou plusieurs matières&nbsp;: l'utilisateur ne verra pas les contenus correspondant aux matières auxquelles il n'est pas associé.</p>
    <p>Il existe cinq types de comptes utilisateurs&nbsp;:</p>
    <ul>
      <li>Les <em>professeurs</em> peuvent modifier/ajouter/supprimer tous les contenus associés à leur(s) matière(s) ou à la matière «&nbsp;Général&nbsp;»&nbsp;: pages d'informations, documents, programmes de colles, cahiers de textes, notes de colles, transferts de documents et agenda. Tous les professeurs associés à une matière y ont les mêmes droits. Tous les professeurs ont les mêmes droits sur les pages d'informations générales et les documents généraux. Le fait de pouvoir administrer globalement le Cahier est découplé du type de compte et nécessite les <em>droits d'administration</em>.</li>
      <li>Les utilisateurs liés à l'administration du <em>lycée</em> peuvent relever les déclarations de colles, voir des statistiques matière par matière et colleur par colleur. Il n'est pas nécessaire d'associer ce type de compte avec toutes les matières.</li>
      <li>Les <em>colleurs</em> peuvent être associés ou non à une ou plusieurs matières. Ils peuvent voir les contenus associés à ces matières et les contenus généraux, si l'accès de ces contenus est autorisé. Ils peuvent mettre des notes dans ces matières et voir leurs notes uniquement. Ils peuvent utiliser les transferts de documents.</li>
      <li>Les <em>élèves</em> peuvent être associés ou non à une ou plusieurs matières. Ils peuvent voir les contenus associés à ces matières et les contenus généraux, si l'accès de ces contenus est autorisé. Ils peuvent voir leurs notes de colles et leurs documents transférés.</li>
      <li>Les <em>invités</em> sont des comptes prévus pour être éventuellement partagés entre plusieurs personnes. Une fois connecté, il est impossible de changer les paramètres du compte (identifiant, mot de passe, matières associées). Les invités peuvent voir les contenus associés à leurs matières et les contenus généraux, si l'accès de ces contenus est autorisé. Il est impossible d'associer une adresse électronique à un compte invité.</li>
    </ul>
    <p>Chaque ressource (information, document) est accessible ou non à chaque type de compte. Cela se décide indépendamment et est modifiable sans restriction par tout professeur associé à la matière.</p>
    <p>Il est possible sur cette page de changer le type d'un compte utilisateur à tout moment, pourvu que cela ne pose pas de problème par rapport aux données déjà saisies (notes de colles et transferts de documents&nbsp;: impossible par exemple de transformer un compte élève qui aurait déjà reçu des notes en compte colleur).</p>
    <p>Tous les utilisateurs (sauf comptes invités) peuvent modifier leur identité et/ou leur mot de passe. Pour la modification d'une adresse électronique, une confirmation est envoyée par courriel pour éviter les erreurs.</p>
    <p>Tous les utilisateurs (sauf comptes invités) peuvent, s'ils ont renseigné une adresse électronique, envoyer des courriels. Mais certains envois peuvent être autorisés ou non par les comptes administrateurs, sur la page des <a href="reglages">réglages généraux</a> du Cahier.</p>
    <h4>Droits d'administration</h4>
    <p>Chaque compte utilisateur de type <em>professeur</em>, <em>lycée</em> ou <em>colleur</em> peut disposer des <em>droits d'administration</em>. Cela permet de pouvoir tout régler au sein du Cahier, comme vous pouvez le faire avec le compte que vous utilisez actuellement.</p>
    <p>Il est possible de tout casser avec des <em>droits d'administration</em>, mais heureusement, ce n'est pas facile et l'ensemble des actions sont réversibles en demandant gentiment de l'aide à l'administrateur du site. Dans le doute, il vaut quand-même mieux éviter de donner ces droits à un(e) collègue qui n'est vraiment pas à l'aise avec l'outil informatique, et/ou qui ne participe pas à la gestion du Cahier.</p>
  </div>

  <div id="aide-creationcompte">
    <h3>Aide et explications</h3>
    <p>Cocher cette case permet d'autoriser ou non des personnes non connectées à demander un compte, mais uniquement un compte élève.</p>
    <p>Cela est particulièrement utile en début d'année&nbsp;: les élèves sont obligés de donner une adresse valide pour recevoir le lien permettant de définir le mot de passe. Fini les formulaires papier où les adresse électroniques sont illisibles&nbsp;!</p>
    <p>Si cette possibilité reste ouverte toute l'année, vous risquez de voir éventuellement arriver une demande de création d'un inconnu en plein milieu d'année. Il est conseillé de laisser cette possibilité ouverte en début d'année et de la refermer une fois tous les élèves inscrits.</p>
    <p>Ces demandes de création de compte peuvent être réalisées par des utilisateurs non connectés, ayant cliqué sur le bouton <span class="icon-connexion"></span>, puis &laquo;&nbsp;Créer un compte&nbsp;&raquo;. Lorsqu'une demande est réalisée, elle doit être validée ou non par un administrateur du Cahier sur cette page. En attendant cette validation, le compte n'est pas utilisable (la connexion est impossible).</p>
  </div>

<?php
fin(true);
?>
