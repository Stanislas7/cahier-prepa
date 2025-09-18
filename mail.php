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

// Accès défini selon le champ mailenvoi dans la table utilisateurs
// Possibilité d'afficher cette page seulement si mailenvoi vaut 1.
$mysqli = connectsql();
if ( !$autorisation )  {
  $titre = 'Envoi de courriel';
  $actuel = false;
  include('login.php');
}

// Enregistrement de destinataires de mails
// Lien : mail?enr_dests&uids=..,..,.. ou mail?enr_dests&gids=..,..
// La première requête avec les identifiants des utilisateurs ou des groupes à
// enregistrer est effacée de la barre d'adresse et on recharge la page sans
// argument. On s'assure simplement que les identifiants sont numériques.
if ( isset($_REQUEST['enr_dests']) )  {
  if ( isset($_REQUEST['uids']) && count($uids = array_filter(explode(',',$_REQUEST['uids']),'ctype_digit')) )
    $_SESSION['dests_u'] = implode(',',$uids);
  elseif ( isset($_REQUEST['gids']) && count($gids = array_filter(explode(',',$_REQUEST['gids']),'ctype_digit')) )
    $_SESSION['dests_g'] = implode(',',$gids);
  header("Location: https://$domaine${chemin}mail");
  exit();
}

// Mode lecture
if ( ( $autorisation == 5 ) || $_SESSION['admin'] )  {
  $donnees = array('action'=>'mail','matiere'=>0,'protection'=>0,'edition'=>0);
  if ( $mode_lecture = $_SESSION['mode_lecture'] )
    $autorisation = $mode_lecture - 1;
  $icones = '<a class="icon-lecture" title="Modifier le mode de lecture"></a>';
}

// Récupération de l'autorisation d'envoi de courriels
if ( $autorisation > 1 )  {
  $resultat = $mysqli->query('SELECT val FROM prefs WHERE nom = "autorisation_mails"');
  $aut_envoi = $resultat->fetch_row()[0] >> 4*($autorisation-2) & 15;
  $resultat->free();
}
if ( !($aut_envoi ?? 0) )  {
  if ( $mode_lecture ?? 0 )  {
    debut($mysqli,'Envoi de courriel',$message,$_SESSION['autorisation'],'mail',$donnees);
    $mysqli->close();
    echo "\n  <div id=\"icones\">\n    <a class=\"icon-lecture mev\" title=\"Modifier le mode de lecture\"></a>\n  </div>\n\n  <article><h2>Cette page n'est pas autorisée pour ce type d'utilisateur.</h2></article>\n\n";
    fin(true);
  }
  debut($mysqli,'Envoi de courriel','Vous n\'avez pas accès à cette page.',$autorisation,' ');
  $mysqli->close();
  fin();
}
$aut_dest = array_reverse(array_keys(str_split('00'.strrev(decbin($aut_envoi))),1));

// Mode lecture : n'afficher qu'un simple message    
if ( $mode_lecture ?? 0 )  {
  debut($mysqli,'Envoi de courriel',$message,$_SESSION['autorisation'],'mail',$donnees);
  $mysqli->close();
  echo "\n  <div id=\"icones\">\n    <a class=\"icon-lecture mev\" title=\"Modifier le mode de lecture\"></a>\n  </div>\n\n  <article><h2>Cette page est autorisée pour ce type d'utilisateur.</h2></article>\n\n";
  fin(true);
}

//////////////
//// HTML ////
//////////////
debut($mysqli,'Envoi de courriel',$message,$autorisation,'mail',$donnees ?? false);

// Récupération des préférences d'envoi (mail, mailexp, mailcopie) de l'utilisateur
$resultat = $mysqli->query("SELECT mail, mailexp, IF(mailcopie,' checked','') AS mailcopie
                            FROM utilisateurs WHERE id = ${_SESSION['id']}");
$u = $resultat->fetch_assoc();
$resultat->free();

// Cas des utilisateurs sans adresse mail
if ( !$u['mail'] )  {
  echo '<article><h3>Votre adresse électronique n\'est pas encore réglée.</h3><p>Vous ne pouvez donc pas écrire ni envoyer de courriel. L\'adresse électronique est à ajouter sur la <a href="prefs">page de vos préférences</a>.</p></article>';
  fin(false);
}

$annonce = ( $autorisation > 2 ) ? 'Merci de n\'envoyer des documents par courriel que si nécessaire.<br>Cette fonctionnalité ne devrait pas être utilisée pour envoyer un document par courriel à tous les élèves&nbsp;: il faut plutôt utiliser la fonctionnalité <em>Documents</em> pour cela.<br>Cette fonctionnalité ne devrait pas être utilisée pour envoyer séparément un document nominatif par courriel à de nombreux élèves&nbsp;: il faut plutôt utiliser la fonctionnalité <em>Transferts de documents</em> pour cela.<br>Envoyer des documents par courriel entraîne une surcharge de travail pour les serveurs et les réseaux (et un risque que G*** pense un jour que Cahier de Prépa est un spammeur...).'
                                 : 'Merci de n\'envoyer des documents par courriel que si nécessaire.<br>Si un <em>Transfert de documents</em> a été mis en place, il faut l\'utiliser.';
// Génération des selects pour ajouter un lien vers un documents du Cahier
$resultat = $mysqli->query("SELECT m.id AS mid, m.nom AS mnom, r.id AS rid, d.id AS did, CONCAT(d.nom,IF(LENGTH(d.ext),CONCAT(' (',d.ext,')'),'')) AS dnom, 
                                   CONCAT_WS('/', ( SELECT GROUP_CONCAT(reps.nom ORDER BY FIND_IN_SET(reps.id,r.parents) SEPARATOR '/') FROM reps WHERE FIND_IN_SET(reps.id,r.parents) ), r.nom) AS rnom
                                   FROM docs AS d LEFT JOIN reps AS r ON r.id = d.parent LEFT JOIN ( (SELECT id, ordre, nom FROM matieres) UNION (SELECT 0, 0, 'Documents généraux') ) AS m ON m.id = r.matiere 
                                   WHERE FIND_IN_SET(m.id,'${_SESSION['matieres']}') AND ".requete_protection($autorisation,'d.').' ORDER BY m.ordre, rnom, nom_nat');
if ( $resultat->num_rows )  {
  $mats = '<option value="-1">[Choisissez une matière]</option>';
  $reps = array( -1 =>'<option value="-1">[Choisissez une matière]</option>');
  $docs = array( -1 => '<option value="0">[Choisissez une matière]</option>', 0 => '<option value="0">[Choisissez un répertoire]</option>' );
  $mid = $rid = -1;
  while ( $r = $resultat->fetch_assoc() )  {
    if ( $r['mid'] != $mid )  { 
      $mid = $r['mid'];
      $mats .= "<option value=\"$mid\">${r['mnom']}</option>";
      $reps[$mid] = '<option value="0">[Choisissez un répertoire]</option>';
    }
    if ( $r['rid'] != $rid )  { 
      $rid = $r['rid'];
      $reps[$mid] .= "<option value=\"$rid\">${r['rnom']}</option>";
      $docs[$rid] = '<option value="0">[Choisissez un document]</option>';
    }
    $docs[$rid] .= "<option value=\"${r['did']}\">${r['dnom']}</option>";
  }
  $resultat->free();
}
?>

  <div id="icones" data-action="page">    <?php echo $icones ?? ''; ?>
    <a class="icon-aide" title="Aide pour l'envoi de courriel"></a>
  </div>

  <article>
    <a class="icon-mailenvoi" title="Envoyer le courriel"></a>
    <p><strong>Destinataires&nbsp;: </strong>&nbsp;<span id="maildest">[Personne]</span>&nbsp;<a class="icon-edite"></a></p>
    <p><strong>Sujet&nbsp;:</strong></p>
    <form id="mail" class="formdoc">
      <input class="ligne" type="text" name="sujet">
      <p><strong>Texte du message&nbsp;:</strong></p>
      <textarea name="texte" rows="20" cols="100"><?php echo "\n\n\n\n-- \n${u['mailexp']}\nMail envoyé depuis <https://$domaine$chemin"; ?>></textarea>
      <p class="ligne"><label for="copie">Recevoir le courriel en copie&nbsp;: </label>
        <input type="checkbox" class="nonbloque" id="copie" name="copie" value="1"<?php echo $u['mailcopie']; ?>>
      </p>
      <p class="ligne"><label for="pj">Pièces jointes éventuelles&nbsp;: </label><input type="file" name="pj[]" multiple></p>
      <input id="videpj" class="ligne" type="button" value="Vider la liste des pièces jointes" style="display: none;">
      <p id="infopj" class="annonce" style="display: none;"><?php echo $annonce; ?></p>
      <p id="infotaillepj" class="annonce" style="display: none;">Les fichiers envoyés par courriel ne doivent pas dépasser <strong>5&nbsp;Mo chacun</strong> et <strong>20&nbsp;Mo au total</strong>.</p>
      <input type="hidden" name="id-copie" value="">
      <input type="hidden" name="id-bcc" value="">
      <input type="hidden" name="action" value="courriel">
    </form>
<?php 
if ( $docs ?? '' )  {
?>
    <h4>Ajouter un lien vers un document du Cahier</h4>
    <p class="ligne"><label for="mat">Matière</label> <select id="mat"><?php echo $mats; ?></select></p>
    <p class="ligne"><label for="rep">Répertoire</label><select id="rep"><?php echo $reps[-1]; ?></select></p>
    <p class="ligne"><label for="doc">Document</label><select id="doc"><?php echo $docs[-1]; ?></select></p>
    <input class="ligne" id="liendoc" type="button" value="Copier le lien vers le document" disabled>
    <script type="text/javascript">
<?php  echo '      reps = '.json_encode($reps).";\n      docs = ".json_encode($docs).";\n    </script>\n";
}
?>
  </article>

  <div id="form-destinataires">
    <a class="icon-aide" title="Aide pour ce formulaire"></a>
    <a class="icon-ok" title="Valider ces destinataires"></a>
    <h3>Choix des destinataires</h3>
    <p class="aide">Les destinataires cochés dans la colonne «&nbsp;Copie&nbsp;» seront explicitement placés en destinataires du courriel. Les destinataires cochés dans la colonne «&nbsp;Copie cachée&nbsp;» recevront le courriel sans que les autres destinataires le sachent.</p>
    <p class="aide">Vous pouvez ne cocher que des destinataires en «&nbsp;Copie cachée&nbsp;», et le seul destinataire visible sera alors vous-même.</p>
    <p class="ligne"><label for="recherche">Rechercher&nbsp;: </label><input type="text" placeholder="Partie de nom, de prénom, de groupe" size="50"></p>
    <p>Actuellement, il y a <span class="nc">0</span> utilisateur<span class="ncs"></span> marqué<span class="ncs"></span> en copie et <span class="ncc">0</span> en copie cachée.</p>
    <form>
    <table class="utilisateurs">
      <thead>
        <tr><th></th><th class="icone">Copie</th><th class="icone">Copie cachée</th></tr>
      </thead>
      <tbody>

<?php

// Récupération des destinataires
// Les comptes de type lycée et les élèves doivent voir tout le monde, sans pliage initial.
// Les professeurs administrateurs doivent voir tous les utilisateurs,
// mais séparer les colleurs/élèves de ses propres matières si matières définies,
// avec pliage initial pour ne voir que ses élèves (ou tous).
// Les professeurs non administrateurs doivent voir les profs et comptes de type lycée,
// mais seulement colleurs/élèves de ses propre matières si matières définies.
// Les colleurs ne doivent voir que les profs de la matière ou administrateurs, les
// comptes de type lycée, les élèves de ses propres matières si matières définies.
$profoucolleur = ( ( $autorisation == 3 ) || ( $autorisation == 5 ) );
// Liste des utilisateurs concernés par l'affichage des groupes d'utilisateurs
$concernes = '';
function affiche_categorie($titre, $categorie, $plie)  {
  $plie = $plie ? ' plie_init' : '';
  echo <<<FIN
          <tr class="categorie$plie">
            <th>$titre</th>
            <th class="icone"><a class="icon-cocher dest" title="Cocher tous les $categorie en copie"></a></th>
            <th class="icone"><a class="icon-cocher bcc" title="Cocher tous les $categorie en copie cachée"></a></th>
          </tr>

FIN;
}

if ( $profoucolleur )
  $restriction_matiere = ( $_SESSION['matieres'] != '0' ) ? '( matieres = "0" OR '. implode(' OR ',array_map(function($m) { return "FIND_IN_SET($m,matieres)"; }, explode(',',str_replace('c','',substr($_SESSION['matieres'],2))) ) ) .' )' : '1';
// Précochage des utilisateurs demandés éventuellement par une première requête
// Identifiants stockés dans $_SESSION['dests_u']
if ( isset($_SESSION['dests_u']) )  {
  $dejacoches = "IF(FIND_IN_SET(id,'${_SESSION['dests_u']}'),' checked','')";
  unset($_SESSION['dests_u']);
}
else
  $dejacoches = '\'\'';
foreach ( $aut_dest as $a )  {
  // Pour les profs, affichage différent pour élèves et colleurs
  if ( ( $autorisation == 5 ) && ( $a < 4 ) )  {
    $titre = ( $a == 2 ) ? 'élèves' : 'colleurs';
    $resultat = $mysqli->query("SELECT IF(${restriction_matiere},1,2) AS cat, id, mailexp, $dejacoches AS dj 
                                FROM utilisateurs WHERE autorisation%10 = $a AND mdp > '0' AND LENGTH(mail) ORDER BY cat, nom, prenom, login");
    if ( $resultat->num_rows )  {
      // On regarde si on a les deux catégories : Mes colleurs et Autres colleurs
      // Si une seule catégorie, regroupement dans une catégorie unique Colleurs
      // Idem pour les élèves. Pliage initial, sauf pour Mes élèves (ou tous).
      $cat1 = $resultat->fetch_assoc()['cat'];
      if ( ( $cat1 == 2 ) && !$_SESSION['admin'] )  {
        $resultat->free();
        continue;
      }
      $resultat->data_seek($resultat->num_rows-1);
      $cat2 = $resultat->fetch_assoc()['cat'];
      $resultat->data_seek(0);
      if ( $cat1 != $cat2 )
        affiche_categorie("Mes $titre", $titre, $a == 3);
      else  {
        $cat2 = -1;
        affiche_categorie(mb_convert_case($titre,MB_CASE_TITLE), $titre, $a == 3);
      }
    }
    while ( $r = $resultat->fetch_assoc() )  {
      if ( $cat2 == $r['cat'] )  {
        if ( !$_SESSION['admin'] )
          continue;
        $cat2 = -1;
        affiche_categorie("Autres $titre", $titre, true);
      }
      echo "        <tr><td>${r['mailexp']}</td><td class=\"icone\"><input type=\"checkbox\" class=\"dest\" value=\"${r['id']}\"${r['dj']}></td><td class=\"icone\"><input type=\"checkbox\" class=\"bcc\" value=\"${r['id']}\"></td></tr>\n";
      $concernes = "$concernes,${r['id']}";
    }
    $resultat->free();
    continue;
  }
  // Pliage initial, pour les profs et colleurs, de ce qui est avant les élèves
  $plie = ( $profoucolleur ) && ( $a > 2 );
  $restriction = ( ( $autorisation == 3 ) && ( $a != 4 ) ) ? "AND ( $restriction_matiere OR autorisation > 10)" : '';
  $resultat = $mysqli->query("SELECT id, mailexp, $dejacoches AS dj FROM utilisateurs WHERE autorisation%10 = $a AND mdp > '0' AND LENGTH(mail) AND id != ${_SESSION['id']} $restriction ORDER BY nom, prenom, login");
  if ( $resultat->num_rows )  {
    switch ( $a )  {
      case 2 : $t = 'Élèves';      $c = 'élèves';                break;
      case 3 : $t = 'Colleurs';    $c = 'colleurs';              break;
      case 4 : $t = 'Lycée';       $c = 'comptes de type lycée'; break;
      case 5 : $t = 'Professeurs'; $c = 'professeurs';
    }
    echo affiche_categorie($t, $c, $plie);
  }
  while ( $r = $resultat->fetch_assoc() )  {
    echo "        <tr><td>${r['mailexp']}</td><td class=\"icone\"><input type=\"checkbox\" class=\"dest\" value=\"${r['id']}\"${r['dj']}></td><td class=\"icone\"><input type=\"checkbox\" class=\"bcc\" value=\"${r['id']}\"></td></tr>\n";
    $concernes = "$concernes,${r['id']}";
  }
  $resultat->free();
}
// Récupération des groupes d'utilisateurs, pour les comptes non élèves seulement
if ( ( $autorisation > 2 ) && ( $concernes = substr($concernes,1) ) )  { 
  // Précochage des utilisateurs demandés éventuellement par une première requête
  // Identifiants stockés dans $_SESSION['dests_u']
  if ( isset($_SESSION['dests_g']) )  {
    $dejacoches = "IF(FIND_IN_SET(g.id,'${_SESSION['dests_g']}'),' checked','')";
    unset($_SESSION['dests_g']);
  }
  else
    $dejacoches = '\'\'';
  foreach ( array('> 2' => 'utilisateurs', '= 2' => 'élèves') as $condition => $type )  {
    $lignes = '';
    $resultat = $mysqli->query("SELECT g.id, g.nom, g.utilisateurs AS uid, $dejacoches as dj,
                                GROUP_CONCAT( u.mailexp ORDER BY u.nom SEPARATOR ', ') AS noms
                                FROM groupes AS g JOIN utilisateurs AS u ON FIND_IN_SET(u.id,g.utilisateurs)
                                WHERE g.mails AND autorisation $condition AND FIND_IN_SET(u.id,'$concernes')
                                GROUP BY g.id ORDER BY g.nom_nat");
    if ( $resultat->num_rows )  {
      echo "        <tr class=\"categorie\"><th>Groupes d'$type</th><th></th><th></th></tr>\n";
      while ( $r = $resultat->fetch_assoc() )
        echo "        <tr class=\"gr\"><td>Groupe ${r['nom']}&nbsp;: ${r['noms']}</td><td class=\"icone\"><input type=\"checkbox\" class=\"dest\" value=\"${r['uid']}\"${r['dj']}></td><td class=\"icone\"><input type=\"checkbox\" class=\"bcc\" value=\"${r['uid']}\"></td></tr>\n";
      $resultat->free();
    }
  }
}
$mysqli->close();

// Aide et formulaire d'ajout
?>

      </tbody>
    </table>
    </form>
  </div>

<?php
// Aide spécifique aux élèves
if ( $autorisation == 2 )  {
?>

  <div id="aide-page">
    <h3>Aide et explications</h3>
    <p>Il est possible ici d'envoyer un courriel à certains utilisateurs, selon un choix défini par les administrateurs du Cahier. L'envoi du courriel est réalisé lors du clic sur le bouton <span class="icon-mailenvoi" style="font-size: 1em;"></span>.</p>
    <h4>Expéditeur</h4>
    <p>Le <em>nom d'expéditeur</em>, l'<em>adresse électronique</em> et le réglage par défaut de la <em>mise en copie des courriels</em> sont modifiables sur la page de <a href="prefs">vos préférences personnelles</a>.</p>
    <p>Le courriel sera envoyé en votre nom mais avec une adresse électronique associée à ce site web pour éviter d'être considéré comme du spam. Le retour du courriel sera positionné sur votre adresse électronique&nbsp;: si vos correspondants répondent à ce mail, ils devraient automatiquement vous répondre.</p>
    <h4>Destinataires</h4>
    <p>La liste des destinataires est éditable à tout moment en cliquant sur le bouton <span class="icon-edite"></span>. Cela ouvre une fenêtre dans laquelle on peut cocher les destinataires. Le bouton <span class="icon-ok"></span> de cette fenêtre valide la liste des destinataires uniquement, n'envoie pas le courriel.</p>
    <p>Les cases à cocher <em>Copie</em> permettent d'ajouter les utilisateurs cochés dans la liste des destinataires visibles. Si vous ne sélectionnez aucun utilisateur, vous serez automatiquement positionné comme destinataire.</p>
    <p>Les cases à cocher <em>Copie cachée</em> permettent d'ajouter les utilisateurs cochés dans la liste des destinataires en copie cachée&nbsp;: les autres destinataires ne verront pas que ce courriel a aussi été envoyé à ceux en copie cachée.</p>
    <p>Un utilisateur ne peut pas être à la fois en copie et en copie cachée.</p>
    <p>Si tous les destinataires sont marqués en copie cachée, alors vous serez l'unique destinataire visible du courriel. </p>
    <p>Un clic sur le nom d'un utilisateur est équivalent à un clic sur la case <em>Copie</em> associée.</p>
    <h4>Sujet et contenu du courriel</h4>
    <p>Le <em>sujet</em> est le titre du courriel. Tous les caractères sont autorisés. Vous devez envoyer vos courriels avec un sujet correspondant explicitement au contenu...</p>
    <p>Le <em>contenu</em> est le corps du courriel, en texte brut. Le courriel envoyé ne sera pas formaté en HTML&nbsp;: il n'est pas possible de réaliser un formattage particulier (changer une taille d'écriture, une police, mettre de la couleur...). Par convention classique,</p>
    <ul>
      <li>écrire un mot entre astérisques (*) signifie le mettre en gras et appuyer sur ce mot.</li>
      <li>écrire un mot entre slashes (/) signifie le mettre en italique pour indiquer qu'il faut y faire attention.</li>
      <li>écrire en majuscules signifie que l'on en train de crier. :-)</li>
    </ul>
    <p>Les <em>pièces jointes éventuelles</em> sont des fichiers qui seront envoyés avec le courriel. Ils ne sont pas stockés sur le serveur. Cette possibilité doit être utilisée de façon marginale, car les courriels ne sont pas faits pour envoyer des gros fichiers, beaucoup moins efficaces techniquement qu'un stockage sur un site comme il est possible de le faire à destination de tous les utilisateurs grâce aux <em>Documents</em> ou à destination d'un élève grâce aux <em>Transferts de documents</em>.</p>
    <h4>Réception en copie</h4>
    <p>Il est aussi possible de recevoir en copie ce mail en cochant la case située en bas de ce formulaire. Si la case est cochée, votre adresse électronique sera placée dans les destinataires en copie cachée.</p>
    <h4>Vie privée</h4>
    <p>Les courriels ne sont pas stockés sur le serveur, ni les pièces jointes. Seuls les courriels qui n'arrivent pas à destination (typiquement, lorsqu'une adresse est pleine) génèrent auprès de l'administrateur général du site un message d'erreur renfermant le contenu du courriel.</p>
  </div>

<?php
}
// Pour les autres utilisateurs
else  {
?>  

  <div id="aide-page">
    <h3>Aide et explications</h3>
    <p>Il est possible ici d'envoyer un courriel aux utilisateurs ayant renseigné leur adresse électronique, selon les réglages modifiables <?php echo ( $_SESSION['admin'] ) ? 'sur la page des <a href="reglages">réglages</a> du Cahier' : 'par les administrateurs du Cahier'; ?>. L'envoi du courriel est réalisé lors du clic sur le bouton <span class="icon-mailenvoi" style="font-size: 1em;"></span>.</p>
    <h4>Expéditeur</h4>
    <p>Le <em>nom d'expéditeur</em>, l'<em>adresse électronique</em> et le réglage par défaut de la <em>mise en copie des courriels</em> sont modifiables sur la page de <a href="prefs">vos préférences personnelles</a>.</p>
    <p>Le courriel sera envoyé en votre nom mais avec une adresse électronique associée à ce site web pour éviter d'être considéré comme du spam. Le retour du mail sera positionné sur votre adresse électronique&nbsp;: si vos correspondants répondent à ce mail, ils devraient automatiquement vous répondre.</p>
    <h4>Destinataires</h4>
    <p>La liste des destinataires est éditable à tout moment en cliquant sur le bouton <span class="icon-edite"></span>. Cela ouvre une fenêtre dans laquelle on peut cocher les destinataires. Les destinataires sont classés par catégories que l'on peut développer ou réduite en cliquant sur les boutons <span class="icon-plie"></span> et <span class="icon-deplie"></span>. Le bouton <span class="icon-ok"></span> de cette fenêtre valide la liste des destinataires uniquement, n'envoie pas le courriel.</p>
    <p>Les cases à cocher <em>Copie</em> permettent d'ajouter les utilisateurs cochés dans la liste des destinataires. Si vous ne sélectionnez aucun utilisateur, vous serez automatiquement positionné comme destinataire.</p>
    <p>Les cases à cocher <em>Copie cachée</em> permettent d'ajouter les utilisateurs cochés dans la liste des destinataires en copie cachée&nbsp;: les autres destinataires ne verront pas que ce courriel a aussi été envoyé à ceux en copie cachée.</p>
    <p>Un utilisateur ne peut pas être à la fois en copie et en copie cachée.</p>
    <p>Si tous les destinataires sont marqués en copie cachée, alors vous serez l'unique destinataire visible du courriel. </p>
    <p>Un clic sur le nom d'un utilisateur est équivalent à un clic sur la case <em>Copie</em> associée.</p>
    <p>Les boutons <span class="icon-cocher"></span> permettent de cocher l'ensemble des utilisateurs du type correspondant.</p>
    <h4>Groupes d'utilisateurs</h4>
    <p>Si des groupes d'utilisateurs ont été établis, ils apparaissent en bas de la liste des destinataires. Cliquer sur les cases correspondantes ou le nom d'un groupe permet de sélectionner automatiquement les utilisateurs concernés.</p>
    <p>Les groupes d\'utilisateurs peuvent être définis ou modifiés <?php echo ( $_SESSION['admin'] ) ? 'sur la page de la <a href="groupes">gestion des groupes</a>' : 'par les administrateurs du Cahier'; ?>.</p>
    <h4>Sujet et contenu du courriel</h4>
    <p>Le <em>sujet</em> est le titre du courriel. Tous les caractères sont autorisés. Pensez à envoyer des courriels avec un sujet correspondant explicitement au contenu...</p>
    <p>Le <em>contenu</em> est le corps du courriel, en texte brut. Le courriel envoyé ne sera pas formaté en HTML&nbsp;: il n'est pas possible de réaliser un formattage particulier (changer une taille d'écriture, une police, mettre de la couleur...). Par convention classique,</p>
    <ul>
      <li>écrire un mot entre astérisques (*) signifie le mettre en gras et appuyer sur ce mot.</li>
      <li>écrire un mot entre slashes (/) signifie le mettre en italique pour indiquer qu'il faut y faire attention.</li>
      <li>écrire en majuscules signifie que l'on en train d'hurler. :-)</li>
    </ul>
    <p>Les <em>pièces jointes éventuelles</em> sont des fichiers qui seront envoyés avec le courriel. Ils ne sont pas stockés sur le serveur. <span style="text-decoration: underline;">Cette possibilité doit être utilisée de façon marginale, car les courriels ne sont pas faits pour envoyer des gros fichiers</span>, beaucoup moins efficaces techniquement qu'un stockage sur un site comme il est possible de le faire à destination de tous les utilisateurs grâce aux <em>Documents</em> ou à destination nominative d'un élève grâce aux <em>Transferts de documents</em>.</p>
    <h4>Ajout de lien vers un document</h4>
    <p>En-dessous du formulaire de conception du courriel se trouve un formulaire de recherche de document. Il permet de récupérer facilement le lien vers un document présent sur le Cahier. Une fois le document choisi, vous devez cliquer sur le bouton <em>Copier le lien vers le document</em> pour avoir le lien dans le presse-papier de votre ordinateur&nbsp;: positionnez ensuite le curseur au bon endroit du courriel et cliquez-droit puis <em>copiez</em> (ou appuyez sur <em>control+V</em> ou <em>pomme+V</em>). Le lien vers le document se trouvera alors dans votre courriel.</p>
    <p>Cette fonctionnalité ne met pas de document en pièce jointe du mail. Le ou les destinataires devront cliquer sur le lien et devront télécharger le document.</p>
    <h4>Réception en copie</h4>
    <p>Il est aussi possible de recevoir en copie ce mail en cochant la case située en bas de ce formulaire. Si la case est cochée, votre adresse électronique sera placée dans les destinataires en copie cachée.</p>
    <h4>Vie privée</h4>
    <p>Les courriels ne sont pas stockés sur le serveur, ni les pièces jointes. Seuls les courriels qui n'arrivent pas à destination (typiquement, lorsqu'une adresse est pleine) génèrent auprès de l'administrateur général du site un message d'erreur renfermant le contenu du courriel.</p>
  </div>

<?php
}
fin(true);
?>
