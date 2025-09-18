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

// Accès aux administrateurs uniquement. Redirection pour les autres.
if ( $autorisation && !$_SESSION['admin'] )  {
  header("Location: https://$domaine$chemin");
  exit();
}
$mysqli = connectsql();
// Si non connecté, demande de connexion
// Si connexion light : on doit répéter son mot de passe pour aller plus loin
// login.php contient fin()
if ( !$autorisation || $_SESSION['light'] )  {
  $titre = 'Réglages généraux';
  $actuel = 'reglages';
  include('login.php');
}

//////////////
//// HTML ////
//////////////
debut($mysqli,'Réglages généraux',$message,$autorisation,'reglages',array('action'=>'prefsglobales'));

// Récupération des préférences
$resultat = $mysqli->query('SELECT nom, val FROM prefs');
$prefs = array();
while ( $r = $resultat->fetch_row() )
  $prefs[$r[0]] = $r[1];
$resultat->free();
$transferts = str_replace( "\"${prefs['transferts_general']}\"", "\"${prefs['transferts_general']}\" selected", '<option value="1">Fonction activée</option><option value="2">Fonction désactivée</option>');

// Récupération du titre de la page d'accueil
$resultat = $mysqli->query('SELECT titre FROM pages WHERE id = 1');
$titre = $resultat->fetch_row()[0];
$resultat->free();

// Génération du select pour la vue mensuelle/hebdomadaire de l'agenda
$select_vue = str_replace("\"${prefs['agenda_vue']}\"", "\"${prefs['agenda_vue']}\" selected", '<option value="1">Mensuelle</option><option value="2">Hebdomadaire</option>');

// Remarque : les data-action ne sont pas pris en compte par la fonction js valide()
// qui est exécutée au clic sur icon-ok. Ces data-action ne servent qu'à l'affichage
// différentié des aides.
?>

  <article data-action="titre" data-id="titre">
    <h3 class="edition">Titre du Cahier</h3>
    <a class="icon-aide" title="Aide"></a>
    <a class="icon-ok" title="Valider la modification"></a>
    <form>
      <input class="ligne" type="text" name="titre" value="<?php echo $titre; ?>" size="80">
    </form>
  </article>

  <article data-action="transferts" data-id="transferts">
    <h3 class="edition">Transferts de documents généraux</h3>
    <a class="icon-aide" title="Aide"></a>
    <a class="icon-ok" title="Valider la modification"></a>
    <form>
      <p class="ligne"><label>Transferts de documents&nbsp;: </label>
        <select name="transferts_general">
          <?php echo $transferts; ?>
        </select>
      </p>
    <p>Cet état ne s'applique qu'aux transferts de documents non associés à une matière spécifique. Ces transferts sont accessibles en cliquant sur l'icône <span class="icon-transfert"></span>, en haut du menu ou en haut de la page sur smartphone/tablette.</p>
    <p>L'activation des transferts de documents associés à chaque matière sont accessibles à la <a href="matieres">page de gestion des matières</a>.</p>
    </form>
  </article>
  
  <article data-action="creationcompte" data-id="creation_compte">
    <h3 class="edition">Gestion des comptes</h3>
    <a class="icon-aide" title="Aide"></a>
    <a class="icon-ok" title="Valider la modification"></a>
    <form>
      <p class="ligne"><label for="creation_compte">Autoriser les demandes de création de comptes élèves&nbsp;: </label>
        <input type="checkbox" name="val"<?php echo $prefs['creation_compte'] ? ' checked' : ''; ?> value="1">
      </p>
    </form>
  </article>

  <article data-action="agenda" data-id="agenda">
    <h3 class="edition">Préférences de l'agenda</h3>
    <a class="icon-aide" title="Aide"></a>
    <a class="icon-ok" title="Valider les modifications"></a>
    <form>
      <p class="ligne"><label for="vue">Vue&nbsp;: </label><select name="vue"><?php echo $select_vue; ?></select></p>
      <p class="ligne" data-protection=<?php echo $prefs['agenda_protection']; ?>><label for="protection">Accès&nbsp;: </label><select id="protection" name="protection[]" multiple data-val32="Agenda désactivé"></select></p>
      <p class="ligne" data-edition=<?php echo $prefs['agenda_edition']; ?>><label for="edition">Édition&nbsp;: </label><select id="edition" name="edition[]" multiple></select></p>
      <p class="ligne"><label for="propagation">Propager ce choix d'accès à chaque événement&nbsp;: </label><input type="checkbox" id="propagation" name="propagation" value="1"></p>
      <h4>Visibilité sur la page d'accueil</h4>
      <p class="ligne"><label for="nbmax">Nombre maximal d'événements affichés sur la page d'accueil&nbsp;: </label><input type="text" id="nbmax" name="nbmax" value="<?php echo $prefs['agenda_nbmax']; ?>" size="3"></p>
      <p class="ligne"><label for="datemax">Nombre maximal de jours affichés sur la page d'accueil&nbsp;: </label><input type="text" id="datemax" name="datemax" value="<?php echo $prefs['agenda_datemax']; ?>" size="3"></p>
      <p class="ligne"><label for="forcedatemax">Propager ces deux valeurs à tous les types d'événements&nbsp;: </label><input type="checkbox" id="forcedatemax" name="forcedatemax" value="1"></p>
    </form>
  </article>
  
  <article data-action="mails" data-id="mails">
    <h3 class="edition">Possibilités d'envoi de courriels</h3>
    <a class="icon-aide" title="Aide"></a>
    <table id="envoimails" class="utilisateurs">
      <tbody>
        <tr>
          <th colspan="2"></th>
          <th class="vertical"><span>Vers les professeurs</span></th>
          <th class="vertical"><span>Vers le lycée</span></th>
          <th class="vertical"><span>Vers les colleurs</span></th>
          <th class="vertical"><span>Vers les éleves</span></th>
        </tr>
<?php
// Pour chaque type : nom complet (dans le select et le tableau), clé, nom affiché dans le tableau des droits d'envoi
$autorisations = array(5=>'les professeurs', 4=>'le lycée', 3=>'les colleurs', 2=>'les élèves');
foreach ( $autorisations as $v => $a )  {
  $envoi = ( $prefs['autorisation_mails'] >> 4*($v-2) ) & 15;
  echo <<<FIN
        <tr data-id="$v">
          <th>Par $a</th><th class="icones"><span class="icon-ok" title="Établir l'autorisation d'envoi générale par $a à tous les utilisateurs"></span>&nbsp;<span class="icon-nok" title="Supprimer l'autorisation d'envoi par $a à tous les utilisateurs"></span></th>
          
FIN;
    for ( $i=5; $i>=2; $i-- )  {
      $ok = ( $envoi >> $i-2 ) & 1;
      echo "<td class=\"icone\">$i|$ok</td>";
    }
    echo "        </tr>\n";
  } 
?>
      </tbody>
    </table>
  </article>

  <div id="aide-titre">
    <h3>Aide et explications</h3>
    <p>Cette case permet de modifier le titre du Cahier. Ce titre est affiché à la fois sur la première page (c'est en fait le titre de cette page) et dans la barre des tâches ou dans les onglets de votre navigateur.</p>
    <p>Il est impossible de laisser vide ce titre.</p>
    <p>Vous pouvez aussi le modifier quand vous êtes sur la première page du Cahier, à l'aide d'un clic sur <span class="icon-prefs"></span> en haut à droite. Ce bouton vous permet aussi de régler les autres paramètres de la page d'accueil, comme le bandeau affiché en cas d'absence d'informations.</p>
  </div>

  <div id="aide-transferts">
    <h3>Aide et explications</h3>
    <p>Il est possible ici de modifier l'accès possible aux transferts de documents non associés à une matière spécifique, accessibles par le lien <span class="icon-transfert"></span> du haut du menu. Ces transferts sont toujours visibles pour les professeurs et les élèves. Ce réglage permet donc d'activer ou non la page des transferts généraux pour les colleurs et les comptes de type lycée.</p>
    <p>Indépendamment de ce choix, seuls les professeurs peuvent ajouter un transfert. Chaque transfert peut être choisi comme allant des encadrants vers les élèves ou dans l'autre sens, impliquant les colleurs ou les comptes de type lycée, ou ne les impliquant pas.</p>
    <p>Lorsqu'un transfert est ajouté, les types de comptes autorisés à accéder au transfert sont automatiquement ajoutés à l'accès à la page des transferts qui était initialement réglé ici. Ce réglage peut donc être automatiquement modifié.</p>
    <p>Un réglage identique existe pour les transferts associés à chaque matière, à la <a href="matieres">gestion des matières</a>.</p>
  </div>

  <div id="aide-creationcompte">
    <h3>Aide et explications</h3>
    <p>Cocher cette case permet d'autoriser ou non des personnes non connectées à demander un compte, mais uniquement un compte élève.</p>
    <p>Cela est particulièrement utile en début d'année&nbsp;: les élèves sont obligés de donner une adresse valide pour recevoir le lien permettant de définir le mot de passe. Fini les formulaires papier où les adresse électroniques sont illisibles&nbsp;!</p>
    <p>Si cette possibilité reste ouverte toute l'année, vous risquez de voir éventuellement arriver une demande de création d'un inconnu en plein milieu d'année. Il est conseillé de laisser cette possibilité ouverte en début d'année et de la refermer une fois tous les élèves inscrits.</p>
    <p>Ces demandes de création de compte peuvent être réalisées par des utilisateurs non connectés, ayant cliqué sur le bouton <span class="icon-connexion"></span>, puis &laquo;&nbsp;Créer un compte&nbsp;&raquo;. Lorsqu'une demande est réalisée, elle doit être validée ou non par un administrateur du Cahier sur la page de <a href="utilisateurs">gestion des comptes</a>. En attendant cette validation, le compte n'est pas utilisable (la connexion est impossible).</p>
  </div>

  <div id="aide-agenda">
    <h3>Aide et explications</h3>
    <p>Ce formulaire permet de modifier les préférences globales de l'agenda. On peut y modifier&nbsp;:</p>
    <ul>
      <li>la <em>vue</em> par défaut, hebdomadaire ou mensuelle.</li>
      <li>l'<em>accès</em> en lecture et en écriture (<em>édition</em>) à l'agenda (voir ci-dessous).</li>
      <li>les réglages de visibilité sur la page d'accueil (<em>nombre d'événements affichés</em> et <em>nombre maximal de jours affichés</em>) (voir ci-dessous).</li>
    </ul>
    <p>Une fois modifié, le formulaire est à valider par un clic sur le bouton <span class="icon-ok"></span>.</p>
    <h4>Réglages des accès en lecture et en écriture</h4>
    <p>Pour l'accès en lecture, trois catégories de choix sont possibles&nbsp;:</p>
    <ul>
      <li><em>Accès public</em>&nbsp;: agenda visible par tout visiteur, sans identification. Les moteurs de recherche ont accès aux textes des événements qui ne sont pas davantage protégés.</li>
      <li><em>Utilisateurs identifiés</em>&nbsp;: agenda visible uniquement par les utilisateurs identifiés à déterminer, en fonction de leur type de compte.</li>
      <li><em>Agenda désactivé</em>&nbsp;: agenda invisible y compris dans le menu, et événements invisibles sur la page d'accueil et sur la page des <a href="recent"><span class="icon-recent"></span>&nbsp;derniers contenus</a>.</li>
    </ul>
    <p>Pour l'accès en écriture, deux catégories de choix existent&nbsp;:</p>
    <ul>
      <li><em>Professeurs uniquement</em>&nbsp;: ajout d'événements et modification de leur visibilité possibles uniquement pour les professeurs, valeur par défaut.</li>
      <li><em>Droits étendus à ...</em>&nbsp;: ces opérations sont autorisées pour les professeurs et les comptes choisis.</li>
    </ul>
    <p>Les professeurs peuvent obligatoirement voir et éditer les événements. On ne peut qu'ajouter d'autres types de comptes. Seuls les utilisateurs qui peuvent voir l'agenda peuvent aussi l'éditer.</p>
    <p>Quelle que soit cette valeur, seuls les professeurs et utilisateurs disposant des droits d'administration peuvent modifier les réglages de l'agenda, ici et sur la page de l'agenda.</p>
    <h4>Propagation des droits d'accès</h4>
    <p>La case à cocher <em>Propager ce choix d'accès à chaque événement</em> permet de modifier les réglages d'accès individuels en lecture et en écriture de chaque événement, en les alignant avec ceux choisis pour l'agenda. Sur la page de l'agenda, les événements dont l'accès en lecture est différent de celui de l'agenda sont repérés par un cadenas <span class="icon-lock"></span> à gauche de leur titre, ceux dont l'accès en écriture est différent de celui de l'agenda sont repérés par un crayon <span class="icon-edite"></span>. Valider ce formulaire en cochant cette case doit supprimer toutes les différences de réglage, donc supprimer toutes les icônes à gauche des titre des événements.</p>
    <p>Si cette case n'est pas cochée, le changement de réglage d'accès à l'agenda n'a donc aucune influence directe sur la visibilité des événements, qui restent éventuellement accessibles sur la page des <a href="recent"><span class="icon-recent"></span>&nbsp;derniers contenus</a>. Cela peut par contre modifier le réglage d'accès en écriture des événements. L'accès en écriture à un événement n'est possible que pour les utilisateurs ayant accès à l'agenda et à l'événement en lecture (il ne dépend pas de l'accès en écriture à l'agenda).</p>
    <h4>Réglage de la visibilité sur la page d'accueil</h4>
    <p>Les prochains événements apparaissent automatiquement sur la page d'accueil, en fonction de plusieurs critères&nbsp;:</p>
    <ul>
      <li>un <em>nombre maximal d'événements affichés</em> global, réglable ici</li>
      <li>un <em>nombre maximal de jours affichés</em> global, réglable ici, qui correspond à la durée maximale sur laquelle les événements sont récupérés pour être affichés</li>
      <li>un <em>nombre maximal d'événements affichés</em> et un <em>nombre maximal de jours affichés</em> spécifiques à chaque type d'événements, modifiables sur la page de <a href="agenda-types">modification des types d'événements</a>. Ces valeurs ne peuvent logiquement pas être supérieures aux valeurs globales et sont ajustées à la baisse si besoin. On peut par exemple, pour le type «&nbsp;Devoirs surveillés&nbsp;», spécifier l'affichage au maximum d'un seul événement.</li>
      <li>une propriété <em>Affichable sur la page d'accueil</em> spécifique à chaque événement, ajustable à l'ajout ou modifiable ultérieurement.</li>
    </ul>
    <p>La case à cocher <em>Propager ces deux valeurs à tous les types d'événements</em> permet de modifier instantanément tous les types d'événements.</p>
  </div>

  <div id="aide-mails">
    <h3>Aide et explications</h3>
    <p>Il est possible ici de régler les possibilités d'envoi de courriels. Le tableau est une double correspondance où chaque échange peut être autorisé (<span class="icon-ok"></span>) ou interdit (<span class="icon-nok"></span>). Un clic sur une de ces icônes commute entre autorisation et interdiction.</p>
    <p>Les boutons <span class="icon-ok"></span> et <span class="icon-nok"></span> en début de ligne permettent de modifier d'un seul clic toutes les possibilités d'envoi de la ligne.</p>
    <p>L'action est immédiate et s'applique instantanément à tous les utilisateurs, même connectés.</p>
    <p>Les courriels déjà envoyés ne seront bien sûr pas bloqués ou détruits.</p>
  </div>

<?php
$mysqli->close();
fin(true);
?>
