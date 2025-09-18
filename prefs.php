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

// Accès aux professeurs, colleurs, élèves connectés uniquement
$mysqli = connectsql();
if ( !$autorisation )  {
  $titre = 'Préférences';
  $actuel = false;
  include('login.php');
}
elseif ( $autorisation < 2 )  {
  debut($mysqli,'Mon compte','Vous n\'avez pas accès à cette page.',$autorisation,' ');
  $mysqli->close();
  fin();
}
// Si connexion light : on doit répéter son mot de passe pour aller plus loin
// login.php contient fin()
if ( $_SESSION['light'] )  {
  $titre = 'Mon compte';
  $actuel = 'prefs';
  include('login.php');
}

//////////////
//// HTML ////
//////////////
debut($mysqli,'Mon compte',$message,$autorisation,'prefs',array('action'=>'prefsperso'));

// Récupération des données de l'utilisateur
$resultat = $mysqli->query("SELECT nom, prenom, mail, menumatieres, timeout, mailexp,
                            IF(mailcopie,' checked','') AS mailcopie,
                            IF(permconn > '',' checked','') AS permconn
                            FROM utilisateurs WHERE id = ${_SESSION['id']}");
$r = $resultat->fetch_assoc();
$resultat->free();
// Autorisation d'envoi de courriel
$resultat = $mysqli->query('SELECT val FROM prefs WHERE nom = "autorisation_mails"');
$aut_envoi = $resultat->fetch_row()[0] >> 4*($autorisation-2) & 15;

// Remarque : les data-action ne sont pas pris en compte par la fonction js valide()
// qui est exécutée au clic sur icon-ok. Ces data-action ne servent qu'à l'affichage
// différentié des aides.
?>

  <article data-action="prefs1" data-id="mdp">
    <h3 class="edition">Modifier mon mot de passe</h3>
    <a class="icon-aide" title="Aide"></a>
    <a class="icon-ok" title="Valider les modifications"></a>
    <form>
      <p class="ligne"><label for="mdp1">Nouveau mot de passe&nbsp;: </label><input type="password" autocomplete="new-password" id="mdp1" name="mdp1" value=""></p>
      <p class="ligne"><label for="mdp2">Confirmation&nbsp;: </label><input type="password" id="mdp2" name="mdp2" value=""></p>
    </form>
  </article>

  <article data-action="prefs2" data-id="identite">
    <h3 class="edition">Mon identité</h3>
    <a class="icon-aide" title="Aide"></a>
    <a class="icon-ok" title="Valider les modifications"></a>
    <form>
      <p class="ligne"><label for="prenom">Prénom&nbsp;: </label><input type="text" id="prenom" name="prenom" value="<?php echo $r['prenom']; ?>" size="50"></p>
      <p class="ligne"><label for="nom">Nom&nbsp;: </label><input type="text" id="nom" name="nom" value="<?php echo $r['nom']; ?>" size="50"></p>
      <p class="ligne"><label for="login">Identifiant&nbsp;: </label><input type="text" id="login" name="login" value="<?php echo $_SESSION['login']; ?>" size="50"></p>
    </form>
  </article>

  <article data-action="prefs3" data-id="mail">
    <h3 class="edition">Mes courriels</h3>
    <a class="icon-aide" title="Aide"></a>
    <a class="icon-ok" title="Valider les modifications"></a>
    <form>
      <p class="ligne"><label for="mail">Adresse électronique&nbsp;: </label><input type="email" id="mail" name="mail" value="<?php echo $r['mail']; ?>" size="50"></p>
      <p class="ligne" style="display: none;"><label for="confirmation">Code de confirmation&nbsp;: </label><input type="text" id="confirmation" name="confirmation" value="" size="50" disabled></p>
      <p>Un code de confirmation va être envoyé par courriel à la nouvelle adresse dans le cas d'une modification d'adresse électronique. Il n'est pas autorisé de saisir ici une adresse électronique utilisée par un autre compte.</p>
      <p class="ligne"><label for="mailexp">Nom affiché comme <?php echo $aut_envoi ? 'expéditeur/' : ''; ?>destinataire de courriel&nbsp;: </label><input type="text" id="mailexp" name="mailexp" value="<?php echo $r['mailexp']; ?>" size="50"></p>
    </form>
  </article>

  <article data-action="prefs4" data-id="reglages">
    <h3 class="edition">Mes réglages techniques</h3>
    <a class="icon-aide" title="Aide"></a>
    <a class="icon-ok" title="Valider les modifications"></a>
    <form>
<?php if ( $aut_envoi ) 
  echo "     <p class=\"ligne\"><label for=\"mailcopie\">Recevoir une copie des courriels envoyés&nbsp;: </label><input type=\"checkbox\" id=\"mailcopie\" name=\"mailcopie\" value=\"1\"${r['mailcopie']}></p>\n";
?>
      <p class="ligne"><label for="permconn">Conserver ma connexion sur cet appareil&nbsp;: </label><input type="checkbox" id="permconn" name="permconn" value="1"<?php echo $r['permconn']; ?>></p>
      <p class="ligne"><label for="timeout">Durée avant déconnexion&nbsp;: </label><input type="text" id="timeout" name="timeout" value="<?php echo $r['timeout']; ?>" size="3"></p>
    </form>
  </article>
<?php
// Matières dans le menu, cas des profs et comptes lycée
if ( $autorisation > 3 )  {
  // Récupération des matières
  $resultat = $mysqli->query("SELECT COUNT(*), GROUP_CONCAT(nom ORDER BY ordre SEPARATOR ', ') FROM matieres WHERE FIND_IN_SET(id,'${_SESSION['matieres']}')");
  $s = $resultat->fetch_row();
  $resultat->free();
  if ( !$s[0] )
    $listematieres = 'Votre compte n\'est associé à aucune matière';
  elseif ( $s[0] == 1 )
    $listematieres = "Votre compte est associé à la matière ${s['1']}";
  else
    $listematieres = "Votre compte est associé aux matières ${s['1']}";
  // Récupération des matières "en tant que colleur" (professeur obligatoirement)
  if ( ( $autorisation == 5 ) && strpos($_SESSION['matieres'],'c') )  {
    $resultat = $mysqli->query("SELECT COUNT(*), GROUP_CONCAT(nom ORDER BY ordre SEPARATOR ', ') FROM matieres WHERE FIND_IN_SET(CONCAT('c',id),'${_SESSION['matieres']}')");
    $s = $resultat->fetch_row();
    $resultat->free();
    $listematieres .= ( $s[0] == 1 ) ? " en tant que professeur, et à la matière ${s[1]} en tant que colleur" : " en tant que professeur, et aux matières ${s[1]} en tant que colleur";
  }
  $textemodif = ( $_SESSION['admin'] ) ? 'est modifiable sur la page de <a href="utilisateurs-matieres">gestion des associations entre utilisateurs et matières</a>' : 'n\'est modifiable que par les administrateurs du Cahier';
  $resultat = $mysqli->query("SELECT id, nom, IF(FIND_IN_SET(id,'${r['menumatieres']}'),' selected','') AS sel FROM matieres 
                              WHERE NOT FIND_IN_SET(id,'".str_replace('c','',$_SESSION['matieres']).'\') ORDER BY ordre');
  if ( $resultat->num_rows )  {
    $select_matieres = '';
    while ( $s = $resultat->fetch_assoc() )
      $select_matieres .= "\n          <option value=\"${s['id']}\"${s['sel']}>${s['nom']}</option>";
    $resultat->free();
  $compte = ( $autorisation == 5 ) ? 'professeurs non associés à la matière' : 'comptes de type lycée';
?>

  <article data-action="prefs5" data-id="menumatieres">
    <h3 class="edition">Mon menu</h3>
    <a class="icon-aide" title="Aide"></a>
    <a class="icon-ok" title="Valider les modifications"></a>
    <p><?php echo "$listematieres. Ceci $textemodif."; ?></p>
    <form>
      <p>Vous pouvez ajouter des matières dans le menu. Seuls les contenus autorisés aux <?php echo $compte;?> s'afficheront.</p>
      <p class="ligne"><label for="matieres">Matières supplémentaires&nbsp;:</label>
        <select multiple name="matieres[]">
          <option value="0">Pas de matière supplémentaire affichée</option><?php echo $select_matieres; ?> 
        </select>
      </p>
    </form>
  </article>
<?php
  }
  else  {
?>

  <article data-id="menumatieres">
    <h3 class="edition">Mon menu</h3>
    <p><?php echo $listematieres; ?>.</p>
    <p>Votre compte est associé à toutes les matières présentes sur le Cahier. Si ce choix a été fait pour que vous puissiez vérifier que tout est correct dans d'autres matières que la/les vôtre(s), sachez que vous pouvez désormais ne conserver l'association qu'à votre/vos matière(s) et ajouter ici même tout ou partie des autres matières dans votre menu. La liste des matières associées à votre compte <?php echo $textemodif; ?>.</p>
  </article>
<?php  
  }
}
?>

  <article id="rgpd">
    <h2>Données personnelles et compatibilité RGPD</h2>
    <p>Les informations recueillies par chaque Cahier de Prépa font l'objet d'un traitement informatique destiné à assurer le fonctionnement du Cahier (envoi de mail, affichage des coordonnées pour les professeurs, inscription des notes de colle). Seules sont stockées les informations strictement nécessaires au bon fonctionnement du service : nom, prénom et adresse électronique. Toutes ces données sont visibles et modifiables ci-dessus.</p>
    <p>Vos données, à l'exception de votre mot de passe, sont aussi accessibles et modifiables par l'ensemble des professeurs de la classe et l'administration de votre lycée, pour permettre le bon fonctionnement du Cahier. Par votre inscription sur ce Cahier, vous autorisez cela ainsi que le stockage de ces informations par l'administrateur du site. Les éventuelles notes de colles des élèves ne sont consultables que par les personnes concernées (élève, colleur, professeur de la matière, administration du lycée), sur les pages dédiées.</p>
    <p>Votre mot de passe vous est complètement personnel. Il est chiffré avant son stockage dans la base de données et ne peut donc techniquement être divulgué à personne.</p>
    <p>La suppression de votre compte doit passer par les professeurs de la classe ou administrateurs du Cahier. Le fonctionnement de la classe peut néanmoins nécessiter la conservation de votre compte au moins jusqu'à la fin de l'année scolaire.</p>
    <p>Les adresses IP ne sont pas conservées dans la base de données, mais chaque action de modification de la base conduit à l'écriture de l'adresse IP utilisée dans un journal.</p>
    <p>Aucun cookie n'est utilisé pour stocker des données. Seul un cookie de session (identifiant permettant de conserver l'identification d'une page à l'autre) est utilisé lorsque vous vous connectez, ainsi qu'un deuxième cookie d'identification lorsque vous activez la fonction de reconnexion automatique.</p>
    <p>Conformément à la <a href="https://www.cnil.fr/fr/loi-78-17-du-6-janvier-1978-modifiee">loi «&nbsp;informatique et libertés&nbsp;» du 6 janvier 1978 modifiée</a>, vous disposez d'un <a href="https://www.cnil.fr/fr/le-droit-dacces">droit d'accès</a> et d'un <a href="https://www.cnil.fr/fr/le-droit-de-rectification">droit de rectification</a> des informations qui vous concernent. Vous pouvez accéder aux informations vous concernant en vous connectant sur votre Cahier de Prépa ou en vous adressant à <a href="mailto:contact@cahier-de-prepa.fr">contact@cahier-de-prepa.fr</a>. Vous pouvez également, pour des motifs légitimes, <a href="https://www.cnil.fr/fr/le-droit-dopposition">vous opposer au traitement des données vous concernant</a>.</p>
    <p>Aucune de ces données ne sera communiquée à une autre organisation. Cahier de Prépa est un logiciel libre, cahier-de-prepa.fr est un service gratuit offert par un professeur de CPGE bénévole, sans publicité et sans vente de données.</p>
    <p>Le traitement des données réalisé par Cahier de Prépa est compatible avec le Réglement Général sur la Protection des Données.</p>
  </article>

  <div id="aide-prefs1">
    <h3>Aide et explications</h3>
    <p>Il est possible ici de modifier votre mot de passe. Une fois modifié, un formulaire est à valider par un clic sur le bouton <span class="icon-ok"></span> correspondant.</p>
    <p>Deux saisies identiques sont nécessaires afin d'éviter les erreurs de frappe.</p>
    <p>Votre mot de passe est stocké uniquement après avoir été chiffré dans la base de données du Cahier de Prépa. Il n'est jamais manipulé sans être préalablement chiffré. Même pour quelqu'un qui pourrait avoir accès directement à la base de données, il est quasiment impossible de récupérer votre mot de passe.</p>
    <p>Vous avez intérêt à utiliser un mot de passe qui vous est personnel, et à éviter les mots de passe faciles à deviner tels que «&nbsp;arnaud75&nbsp;» ou «&nbsp;pikachu&nbsp;» (reprenant des mots entiers et une passion, une identité, une adresse...) pour éviter que quelqu'un puisse deviner votre mot de passe. Vous avez intérêt aussi à utiliser le même mot de passe sur tous les Cahiers de Prépa où vous pourriez vous connecter, c'est plus simple et sans risque.</p>
  </div>

  <div id="aide-prefs2">
    <h3>Aide et explications</h3>
    <p>Il est possible ici de modifier votre identité. Une fois modifié, un formulaire est à valider par un clic sur le bouton <span class="icon-ok"></span> correspondant.</p>
    <p>Ce formulaire sert particulièrement à corriger les erreurs de saisie dans votre nom-prénom. S'ils sont inversés, vous pouvez les échanger à nouveau.</p>
    <p>Votre <em>identifiant</em> de connexion est initialement de la forme &laquo;&nbsp;jdupont&nbsp;&raquo;. Vous pouvez le modifier et y mettre ce que vous souhaitez, y compris un simple prénom ou un pseudo, à condition de ne pas demander un identifiant déjà existant dans la base. Remarque&nbsp;: les professeurs et administrateurs du site peuvent voir cet identifiant.</p>
  </div>

  <div id="aide-prefs3">
    <h3>Aide et explications</h3>
    <p>Il est possible ici de modifier votre adresse électronique. Une fois modifié, un formulaire est à valider par un clic sur le bouton <span class="icon-ok"></span> correspondant.</p>
    <p>Pour modifier votre <em>adresse électronique</em>, vous devrez saisir cette nouvelle adresse et récupérer un <em>code de confirmation</em> de 8 caractères envoyé par courriel à cette adresse. Ce code est valable environ une heure (jusqu'à la prochaine heure pleine). La demande s'annule automatiquement si vous ne donnez pas suite. Vous pouvez demander à recevoir ce courriel autant de fois que vous le souhaitez.</p>
    <p>Il n'est pas possible d'affecter la même adresse électronique à deux comptes différents sur un même Cahier de Prépa.</p>
    <p>L'adresse électronique peut remplacer l'identifiant lorsque vous vous connectez, mais sert bien entendu à envoyer/recevoir des courriels à l'aide du Cahier.</p>
    <p>Vous pouvez de plus modifier le <em>Nom affiché comme <?php echo $aut_envoi ? 'expéditeur/' : ''; ?>destinataire de courriel</em>, qui est utilisé dans les entêtes d'expédition des courriels.</p>
  </div>

  <div id="aide-prefs4">
    <h3>Aide et explications</h3>
    <p>Il est possible ici de modifier vos préférences de connexion. Une fois modifié, un formulaire est à valider par un clic sur le bouton <span class="icon-ok"></span> correspondant.</p>
<?php if ( $aut_envoi ) 
  echo '    <p>La case à cocher <em>Recevoir une copie des courriels envoyés</em> n\'est qu\'une valeur par défaut. Une case à cocher se trouve sur la page de <a href="mail">rédaction des courriels</a>, vous permettant de modifier au cas par cas ce réglage. Il permet de recevoir sur son adresse électronique une copie des courriels envoyés, afin de l\'archiver par exemple.</p>';
?> 
    <p>Cocher la case <em>conserver ma connexion sur cet appareil</em> permet d'établir une reconnexion automatique à chaque visite, y compris d'un jour à l'autre. Cette connexion automatique permet de lire la plupart des contenus sans retaper son mot de passe. Pour des contenus sensibles ou des modifications du site, la saisie du mot de passe sera redemandée si la dernière visite est trop ancienne.</p>
    <p>La <em>durée avant déconnexion</em> correspond à la durée en secondes avant laquelle la déconnexion automatique a lieu. Si la case <em>conserver ma connexion sur cet appareil</em> n'est pas cochée, le site vous déconnectera et vous demandera de vous reconnecter complètement pour accéder à toute page protégée en lecture si vous laissez passer cette durée entre deux clics. Si la case <em>conserver ma connexion sur cet appareil</em> est cochée, il s'agit simplement d'une demande de mot de passe, et uniquement pour les pages sensibles du Cahier.</p>
    <h4>Conseils</h4>
    <p>La connexion automatique est indépendante à chaque appareil. Vous pouvez l'activer directement à la première connexion, sans venir jusqu'ici.</p>
    <p>Il est conseillé de cocher la case <em>conserver ma connexion sur cet appareil</em> sur les appareils sûrs&nbsp;: l'ordinateur à la maison, votre téléphone s'il se bloque au bout de quelques minutes d'inactivité. Il est conseillé de ne pas cocher cette case sur les appareils insuffisamment sûrs&nbsp;: typiquement, les ordinateurs du lycée, en salle de cours ou en salle des profs, surtout si vous avez tendance à vous absenter en laissant l'écran allumé sur votre session, avec des élèves ou des collègues farceurs dans la salle. Remarquez que c'est une très mauvaise idée et que d'autres problèmes plus importants pourraient survenir en faisant cela...</p>
    <p>Pour la <em>durée avant déconnexion</em>, un bon compromis est à trouver entre une reconnexion permanente et un risque sécuritaire trop important. Une valeur de 3600 (soit une heure) semble correcte.</p>
    <h4>Détails techniques</h4>
    <p>La connexion automatique est possible grâce à l'enregistrement dans votre navigateur d'un identifiant spécial, au sein d'un <em>cookie</em>. C'est le deuxième et dernier cookie qu'enregistre Cahier de Prépa sur votre machine, le premier étant nécessaire pour la simple navigation d'une page à l'autre (il est donc obligatoire à partir du moment où vous avez saisi votre mot de passe). La présence ou non de ce cookie sur votre navigateur permet d'établir ou non la connexion automatique, qui dépend donc bien de l'appareil. Vous pouvez le vérifier en visitant votre Cahier en activant la «&nbsp;navigation privée&nbsp;» dans votre navigateur&nbsp;: votre Cahier ne vous reconnaît plus.</p>
    <p>Il ne s'agit pas d'une reconnaissance par adresse IP par exemple. Aucune donnée personnelle supplémentaire n'est stockée sur vous pour assurer la connexion automatique.</p>
  </div>

  <!-- professeurs uniquement -->
  <div id="aide-prefs5">
    <h3>Aide et explications</h3>
    <p>Il est possible ici de modifier les <em>matières supplémentaires</em> affichées dans votre menu. Une fois modifié, un formulaire est à valider par un clic sur le bouton <span class="icon-ok"></span> correspondant.</p>
    <p>L'affichage par défaut, pour ne pas surcharger le menu, est de ne vous y afficher que les matières qui vous sont associées. Mais si vous le souhaitez, vous pouvez ajouter des matières de vos collègues. Cela ne vous donne pas de droits supplémentaires sur ces contenus, mais simplement la possibilité de les voir d'un simple clic. Vous ne pourrez alors voir que les resources dont l'accès est autorisé aux professeurs non associés à la matière. En particulier, les notes de colles et les transferts de documents personnels n'en font pas partie.</p>
    <p>Seuls les professeurs ont cette possibilité d'ajout de matières dans le menu. Les élèves, colleurs, comptes de type lycée ne peuvent pas ajouter au menu des matières qui ne leur sont pas associées.</p>
    <p>Dans le menu, vos matières sont bien entendu affichées en premier, suivi des <em>matières supplémentaires</em>. L'ordre des matières au sein de ces deux catégories est celui défini par la page de <a href="matieres">gestion des matières</a>, qui est notamment utilisé pour les menus des élèves.</p>
  </div>

<?php
$mysqli->close();
fin(true);
?>
