<?php
// Sécurité : ne doit pas être exécuté s'il n'est pas lancé par un script autorisé
if ( !defined('OK') )  exit();

// Variables fournies par le script appelant : $titre, $actuel, $mysqli

////////////
/// HTML ///
////////////
$lien = ( $actuel ) ? '' : '<br><a href=".">Retour à la page d\'accueil</a>';
debut($mysqli,$titre,'',$autorisation,$actuel);
if ( isset($_SESSION['light']) && $_SESSION['light'] )
  echo <<<FIN
  
  <article id="connexion">
    <a class="icon-ok" title="Valider"></a>
    <h3>Vérification de mot de passe</h3>
    <form>
      <p>Cette page contient des données sensibles. Vous êtes déjà connecté, mais vous devez fournir à nouveau votre mot de passe pour y accéder.</p>
      <input class="ligne" type="password" name="motdepasse" autofocus placeholder="Mot de passe">
    </form>
  </article>
FIN;
else  {
  $resultat = $mysqli->query('SELECT val FROM prefs WHERE nom = "creation_compte"');
  $creationcompte = ( $resultat->fetch_row()[0] ) ? '<p class="oubli"><a href="gestioncompte?creation">Demander une création de compte</a></p>' : '';
  $resultat->free();
  echo <<<FIN

  <div class="warning">Ce contenu est protégé. Vous devez vous connecter pour l'afficher.$lien</div>
  
  <article id="connexion">
    <a class="icon-ok" title="Valider"></a>
    <h3>Se connecter</h3>
    <form>
      <p>Veuillez entrer votre identifiant et votre mot de passe&nbsp;:</p>
      <input class="ligne" type="text" name="login" autofocus placeholder="Identifiant">
      <input class="ligne" type="password" name="motdepasse" placeholder="Mot de passe">
      <p class="souvenir"><label for="permconn">Se souvenir de moi</label><input type="checkbox" name="permconn" id="permconn" value="1">
      <p class="oubli"><a href="gestioncompte?oublimdp">Identifiant ou mot de passe oublié&nbsp;?</a></p>
      $creationcompte
    </form>
  </article>
FIN;
}
$mysqli->close();

echo <<<FIN

  <script type="text/javascript">
$( function() {
  $('#connexion a.icon-ok').on("click",function () {
    $.ajax({url: 'ajax.php',
            method: "post",
            data: $('#connexion form').serialize()+'&connexion=1', 
            dataType: 'json' 
          });
  });
  $('#connexion input').entreevalide($('#connexion form'));
});
  </script>
    
FIN;
fin();
?>
