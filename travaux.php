<?php
// Script indiquant des travaux temporaires. 
// Prévu pour être appelé directement dans config.php, dans lequel on doit
// temporairement placer "include('travaux.php');" en toute fin de script.

// Sécurité : ne doit pas être exécuté s'il n'est pas lancé par un script autorisé
if ( !defined('OK') )  exit();

// Connexion SQL potentiellement impossible.

?>
<!doctype html>
<html lang="fr">
<head>
  <title>Cahier de Prépa</title>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, height=device-height, initial-scale=1.0">
  <link rel="stylesheet" href="css/style.min.css?v=1100">
</head>

<header></header>

<section>
  <article><h1>Ce Cahier de Prépa est actuellement en maintenance.</h1></article>
  <article><p class="annonce">Il est par conséquent temporairement indisponible. Mais rassurez-vous, il revient très vite&nbsp;!</p></article>
</section>

<footer>Ce site est réalisé par le logiciel <a href="http://cahier-de-prepa.fr">Cahier de prépa</a>, publié sous <a href="Licence_CeCILL_V2-fr.html">licence libre</a>.&nbsp;<span class="icon-theme" title="Changer le thème lumineux/sombre"></span>&nbsp;<a class="icon-python" href="/basthon" title="Coder en Python directement dans votre navigateur"></a></footer>

</body>
</html>
<?php exit(); ?>
