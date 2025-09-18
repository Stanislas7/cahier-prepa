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

// Accès aux comptes lycée et administrateurs uniquement. Redirection pour les autres.
if ( $autorisation && ( $autorisation != 4 ) && !$_SESSION['admin'] )  {
  header("Location: https://$domaine$chemin");
  exit();
}
$mysqli = connectsql();
// Si connexion light : on doit répéter son mot de passe pour aller plus loin
// login.php contient fin()
if ( !$autorisation || $_SESSION['light'] )  {
  $titre = 'Relève des déclarations de colle';
  $actuel = 'relevecolles';
  include('login.php');
}

// Fonction pour l'affichage des durées en heures/minutes. Argument en minutes.
function format_duree($duree)  {
  if ( $duree == 0 )
    return '-';
  if ( $duree >= 60 )
    return intdiv($duree,60).'h'.( $duree%60 ?: '');
  return ($duree%60).'m';
}
function format_duree_eleves($duree,$eleves)  {
  if ( $duree == 0 )
    return '-';
  if ( $duree >= 60 )
    return intdiv($duree,60).'h'.( $duree%60 ?: '')." ($eleves)";
  return ($duree%60)."m ($eleves)";
}

// Actions spécifiques : lancement de scripts 
// Ces scripts contiennent fin()
if ( isset($_REQUEST['stats']) )
  include('relevecolles-statistiques.php');
elseif ( isset($_REQUEST['detail']) )
  include('relevecolles-detail.php');

///////////////////////////////////////////
// Exportation en xls ou pour impression //
///////////////////////////////////////////
// Trois types d'exportation possible :
// * decompte -> liste des colleurs/heures déclarées, en xls
// * notes -> liste des notes, en xls
// * impression -> affichage des deux listes en html, pour l'impression
// Exportation uniquement si aucun header déjà envoyé
if ( isset($_REQUEST['export']) && ctype_digit($date = $_REQUEST['datereleve'] ?? '') && !headers_sent() )  {
  // Récupération du titre du Cahier
  $resultat = $mysqli->query('SELECT titre FROM pages WHERE id = 1');
  $r = $resultat->fetch_row();
  $resultat->free();
  $titre = $r[0];
  // Récupération de la bonne date
  $datesql = preg_replace('/(\d{2})(\d{2})(\d{2})/','20$1-$2-$3',$date);
  $date = preg_replace('/(\d{4})-(\d{2})-(\d{2})/','$3/$2/$1',$datesql);
  // Récupération des mois concernés, pour ajouter au titre
  $mois = array('Décembre','Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre');
  $resultat = $mysqli->query("SELECT MONTH(jour) + 12*(YEAR(jour)-2000) AS mois FROM heurescolles
                              WHERE releve = '$datesql' GROUP BY mois HAVING COUNT(*) > 10 ORDER BY mois");
  if ( !$resultat->num_rows )  // Si le seuil de 10 heures est trop grand
    $resultat = $mysqli->query("SELECT MONTH(jour) + 12*(YEAR(jour)-2000) AS mois FROM heurescolles
                                WHERE releve = '$datesql' GROUP BY mois ORDER BY mois");
  $titremois = array();
  while ( $r = $resultat->fetch_row() )
    $titremois[] = $mois[ $r[0]%12 ];
  $titremois = implode(', ',$titremois);
  // Requêtes possibles
  $requete_decompte = "SELECT IF(LENGTH(c.nom),CONCAT(c.nom,' ',c.prenom),c.login) AS colleur, m.nom AS matiere,
                              SUM(nb) AS nb, SUM(duree*(description>'')) AS duree_td, SUM(duree) AS duree
                      FROM heurescolles AS h LEFT JOIN utilisateurs AS c ON colleur = c.id LEFT JOIN matieres AS m ON matiere = m.id
                      LEFT JOIN (SELECT COUNT(*) AS nb, heure FROM notescolles GROUP BY heure) AS n ON h.id = n.heure
                      WHERE h.releve = '$datesql' GROUP BY h.colleur,matiere ORDER BY c.nom, m.ordre";
  $requete_notes =    "SELECT h.id, IF(LENGTH(c.nom),CONCAT(LEFT(c.prenom,1),'. ',c.nom),c.login) AS colleur, eleve,
                              m.nom AS matiere, DATE_FORMAT(jour,'%d/%m/%y') AS jour, duree, note, description
                      FROM heurescolles AS h LEFT JOIN utilisateurs AS c ON colleur = c.id LEFT JOIN matieres AS m ON matiere = m.id
                      LEFT JOIN ( SELECT heure, note, IF(LENGTH(nom),CONCAT(LEFT(prenom,1),' ',nom),login) AS eleve 
                                  FROM notescolles JOIN utilisateurs AS e ON eleve = e.id ) AS n ON n.heure = h.id
                      WHERE releve = '$datesql' ORDER BY c.nom, h.jour, n.heure, eleve";
  // Fonctions de saisie xls
  function saisie_nombre($l, $c, $v)  {
    echo pack("sssss", 0x203, 14, $l, $c, 0).pack("d", $v);
  }
  function saisie_chaine($l, $c, $v)  {
    echo pack("ssssss", 0x204, 8 + strlen($v), $l, $c, 0, strlen($v)).$v;
  }
  // Exportation
  switch ( $_REQUEST['export'] )  {
    case 'decomptexls':  {
      $resultat = $mysqli->query($requete_decompte);
      $mysqli->close();
      if ( $resultat->num_rows )  {
        // Envoi des headers
        header("Content-Type: application/vnd.ms-excel");
        header("Content-Disposition: attachment; filename=decompte-$datesql.xls");
        header("Content-Transfer-Encoding: binary");
        // Début du fichier xls
        echo pack("sssss", 0x809, 6, 0, 0x10, 0);
        // Remplissage
        saisie_chaine(0, 0, utf8_decode("Relève des heures de colles du $date"));
        saisie_chaine(1, 0, utf8_decode($titre));
        saisie_chaine(2, 0, utf8_decode($titremois));
        saisie_chaine(4, 0, 'Colleur');
        saisie_chaine(4, 1, utf8_decode('Matière'));
        saisie_chaine(4, 2, utf8_decode('Heures de colles'));
        saisie_chaine(4, 3, utf8_decode('Nombre d\'élèves'));
        saisie_chaine(4, 4, utf8_decode('Séances sans note'));
        saisie_chaine(4, 5, utf8_decode('Durée totale'));
        saisie_chaine(4, 6, utf8_decode('Durée totale en minutes'));
        $i = 4;
        $total_n = $total_duree = $total_duree_td = 0;
        while ( $r = $resultat->fetch_assoc() )  {
          saisie_chaine(++$i, 0, utf8_decode($r['colleur']));
          saisie_chaine($i, 1, utf8_decode($r['matiere']));
          saisie_chaine($i, 2, format_duree($r['duree']-$r['duree_td']));
          saisie_nombre($i, 3, $r['nb']);
          saisie_chaine($i, 4, format_duree($r['duree_td']));
          saisie_chaine($i, 5, format_duree($r['duree']));
          saisie_nombre($i, 6, $r['duree']);
          $total_n += $r['nb'];
          $total_duree_td += $r['duree_td'];
          $total_duree += $r['duree'];
        }
        // Totaux
        saisie_chaine($i = $i+2, 0, 'Total');
        saisie_chaine($i, 2, format_duree($total_duree-$total_duree_td));
        saisie_nombre($i, 3, $total_n);
        saisie_chaine($i, 4, format_duree($total_duree_td));
        saisie_chaine($i, 5, format_duree($total_duree));
        saisie_nombre($i, 6, $total_duree);
        // Fin du fichier xls
        echo pack("ss", 0x0A, 0x00);
        $resultat->free();
      }
      exit();
    }
    case 'notes':  {
      $resultat = $mysqli->query($requete_notes);
      $mysqli->close();
      if ( $resultat->num_rows )  {
        // Envoi des headers
        header("Content-Type: application/vnd.ms-excel");
        header("Content-Disposition: attachment; filename=notes-$datesql.xls");
        header("Content-Transfer-Encoding: binary");
        // Début du fichier xls
        echo pack("sssss", 0x809, 6, 0, 0x10, 0);
        // Remplissage
        saisie_chaine(0, 0, utf8_decode("Détail des notes de colles - Relève du $date"));
        saisie_chaine(1, 0, utf8_decode($titre));
        saisie_chaine(2, 0, utf8_decode($titremois));
        saisie_chaine(4, 0, 'Colleur');
        saisie_chaine(4, 1, utf8_decode('Matière'));
        saisie_chaine(4, 2, utf8_decode('Élève/Description'));
        saisie_chaine(4, 3, utf8_decode('Note'));
        saisie_chaine(4, 4, utf8_decode('Date'));
        saisie_chaine(4, 5, utf8_decode('Nb d\'élèves'));
        saisie_chaine(4, 6, utf8_decode('Durée déclarée'));
        $i = 4;
        $n = $hid = 0;
        while ( $r = $resultat->fetch_assoc() )  {
          saisie_chaine(++$i, 0, utf8_decode($r['colleur']));
          saisie_chaine($i, 1, utf8_decode($r['matiere']));
          if ( $r['eleve'] )  {
            saisie_chaine($i, 2, utf8_decode($r['eleve']));
            saisie_chaine($i, 3, utf8_decode($r['note']));
          }
          else 
            saisie_chaine($i, 2, utf8_decode($r['description']));
          if ( $hid != $r['id'] )  {
            saisie_chaine($i, 4, utf8_decode($r['jour']));
            saisie_chaine($i, 6, format_duree($r['duree']));
            if ( $n )
              saisie_nombre($i-$n, 5, $n);
            $hid = $r['id'];
            $n = 0;
          }
          $n = $n+empty($r['description']);
        }
        if ( $n )
          saisie_nombre($i-$n+1, 5, $n);
        // Fin du fichier xls
        echo pack("ss", 0x0A, 0x00);
        $resultat->free();
      }
      exit();
    }
    case 'decomptepdf':  {
      echo <<<FIN
<!doctype html>
<html lang="fr">
<head>
  <title>Impression</title>
  <meta charset="utf-8">
  <link rel="stylesheet" href="css/style.min.css">
</head>
<body>
<header>
  <h1 style="text-align:left;">Relève des colles du $date<br>$titre<br>$titremois</h1>
</header>

FIN;
      // Décompte des heures
      $resultat = $mysqli->query($requete_decompte);
      if ( $resultat->num_rows )  {
        echo <<<FIN
  <article>
    <table style="text-align:left; padding: 10px;">
      <tbody>
        <tr><th>Colleur</th><th>Matière</th><th>Heures&nbsp;de&nbsp;colles (nombre&nbsp;d'élèves)</th><th>Séances sans&nbsp;note</th><th>Durée&nbsp;totale</th><th>Durée&nbsp;totale en&nbsp;minutes</th></tr>
FIN;
        // Remplissage
        $total_n = $total_duree = $total_duree_td = 0;
        while ( $r = $resultat->fetch_assoc() )  {
          echo "\n        <tr><th>${r['colleur']}</th><td>${r['matiere']}</td><td>".format_duree_eleves($r['duree']-$r['duree_td'],$r['nb']).'</td><td>'.format_duree($r['duree_td']).'</td><td>'.format_duree($r['duree'])."</td><td>${r['duree']}</td></tr>";
          $total_n += $r['nb'];
          $total_duree_td += $r['duree_td'];
          $total_duree += $r['duree'];
        }
        $resultat->free();
        echo "\n        <tr><th>Total</th><td></td><th>".format_duree_eleves($total_duree-$total_duree_td,$total_n).'</th><th>'.format_duree($total_duree_td).'</th><th>'.format_duree($total_duree)."</th><th>$total_duree</th></tr>\n      </tbody>\n    </table>\n  </article>\n\n";
      }
      exit("</body>\n</html>\n");
    }
  }
}

//////////////
//// HTML ////
//////////////
debut($mysqli,'Relève des déclarations de colles',$message,$autorisation,'relevecolles',array('action'=>'relevecolles','css'=>'datetimepicker'));
echo <<<FIN

  <div id="icones">
    <a class="icon-aide" title="Aide pour les relèves de déclarations de colles"></a>
  </div>
  
  <article>
    <input onclick="location.href='relevecolles'" type="button" class="ligne" value="Relève des déclarations de colles" disabled>
    <input onclick="location.href='?stats'" type="button" class="ligne" value="Statistiques par matière et par colleur">
    <input onclick="location.href='?detail'" type="button" class="ligne" value="Détail de toutes les heures déclarées">
  </article>

FIN;

// Restriction de date : date max des colles relevées
if ( $restrictiondate = boolval( ( $datemax = preg_filter('/(\d{2})-(\d{2})-(\d{4})/','$3-$2-$1',$_REQUEST['datemax'] ?? '') ) && ( $datemax <= date('Y-m-d') ) ) )
  $date = substr($datemax,8).'/'.substr($datemax,5,2).'/'.substr($datemax,0,4);
else  {
  $date = date('d/m/Y');
  $datemax = date('Y-m-d');
}

// Vérification d'une relève aujourd'hui
$resultat = $mysqli->query('SELECT id FROM heurescolles WHERE releve = CURDATE() LIMIT 1');
if ( $releveaujourdhui = ( $resultat->num_rows > 0 ) )
  $resultat->free();

// Récupération des heures à relever, matière par matière
$resultat = $mysqli->query("SELECT m.nom AS matiere, SUM(nb) AS nb, SUM(duree*(description>'')) AS duree_td, SUM(duree) AS duree
                            FROM heurescolles AS h LEFT JOIN matieres AS m ON matiere = m.id
                            LEFT JOIN (SELECT COUNT(*) AS nb, heure FROM notescolles GROUP BY heure) AS n ON h.id = n.heure
                            WHERE releve = 0 AND jour <= '$datemax' GROUP BY m.id ORDER BY ordre");
$aff = ( $resultat->num_rows > 0 );
// Affichages
echo "\n  <article data-action=\"releve\">\n    <h3 class=\"edition\">Prochaine relève</h3>\n    <a class=\"icon-aide\" title=\"Aide\"></a>\n";

if ( $releveaujourdhui )
  echo $aff ? "\n    <div class=\"annonce\">Une relève a déjà été réalisée aujourd'hui et apparaît ci-dessous. Si vous en refaites une, les colles relevées seront simplement ajoutées à la relève existante.</div>\n" : "\n    <div class=\"annonce\">Une relève a été réalisée aujourd'hui et apparaît ci-dessous.</div>\n";
if ( $aff || $restrictiondate )
  echo <<<FIN
    <p class="ligne">
      <label for="datemax">Arrêter le comptage au (inclus)&nbsp;:</label>
      <input id="datemax" type="text" value="$date" onchange="window.location='?datemax='+this.value.replace(/\//g,'-');">
    </p>
FIN;
if ( $aff )  {
  $bouton = ( $autorisation == 4 ) ? "\n    <input id=\"relevecolles\" type=\"button\" class=\"ligne\" value=\"Relever les heures\">" : '';
  echo <<<FIN
    <table class="centre">
      <tbody>
        <tr><th>Matière</th><th>Colles (élèves)</th><th>Séances sans note</th><th>Durée totale</th></tr>

FIN;
  while ( $r = $resultat->fetch_assoc() )
    echo "      <tr><td>${r['matiere']}</td><td>".format_duree_eleves($r['duree']-$r['duree_td'],$r['nb']).'</td><td>'.format_duree($r['duree_td']).'</td><td>'.format_duree($r['duree'])."</td></tr>\n";
  echo <<<FIN
      </tbody>
    </table>$bouton
  </article>

FIN;
  $resultat->free();
}
else
  echo $restrictiondate ? "    <div class=\"annonce\">Il n'y a aucune heure de colle à relever avant le $date.</div>\n  </article>\n\n" : "    <div class=\"annonce\">Il n'y a actuellement aucune nouvelle heure de colle à relever.</div>\n  </article>\n\n";

// Récupération des relevés déjà réalisés
$resultat = $mysqli->query('SELECT DATE_FORMAT(releve,\'%d/%m/%y\') AS date, DATE_FORMAT(releve,\'%y%m%d\') AS ref, SUM(duree) AS duree, SUM(nb) AS nb
                            FROM heurescolles AS h LEFT JOIN (SELECT COUNT(*) AS nb, heure FROM notescolles GROUP BY heure) AS n ON h.id = n.heure
                            WHERE releve > 0 GROUP BY releve ORDER BY releve DESC');
echo "\n  <article data-action=\"dejareleve\">\n    <h3 class=\"edition\">Relèves déjà réalisées</h3>\n    <a class=\"icon-aide\" title=\"Aide\"></a>\n";
if ( $resultat->num_rows )  {
  echo <<<FIN
    <table id="notes" class="centre">
      <tbody>
        <tr><th>Date</th><th>Durée - Nombre de notes</th><th>Détail des notes</th><th>Décomptes des heures</th></tr>

FIN;
  while ( $r = $resultat->fetch_assoc() )
    echo "        <tr><td>${r['date']}</td><td>".format_duree($r['duree'])." - ${r['nb']} notes</td><td><a class=\"icon-doc-xls\" href=\"?export=notes&datereleve=${r['ref']}\"></a></td><td>
    
    <a class=\"icon-doc-xls\" href=\"?export=decomptexls&datereleve=${r['ref']}\" title=\"Télécharger en format XLS\"></a>
    &nbsp;<a class=\"icon-doc-pdf\" onclick=\"printPage('?export=decomptepdf&datereleve=${r['ref']}');\" title=\"Télécharger en format PDF\"></a>
    &nbsp;<a class=\"icon-voir\" href=\"?export=decomptepdf&datereleve=${r['ref']}\" target=\"_blank\" title=\"Voir directement dans le navigateur\"></a></td></tr>\n";
  echo "      </tbody>\n    </table>\n  </article>\n\n";
  $resultat->free();
}
else
  echo "<div class=\"annonce\">Vous n'avez pas encore relevé de colles cette année.</div>\n  </article>\n\n";
$mysqli->close();
?>

  <div id="aide-relevecolles">
    <h3>Aide et explications</h3>
    <p>Cette page est disponible pour les utilisateurs ayant un compte de type lycée et pour les administrateurs du Cahier, mais toutes les modifications ne sont possibles que par les utilisateurs ayant un compte de type lycée.</p>
    <p>Elle permet de visualiser le détail des heures de colles et TD déclarées par les colleurs et les professeurs, de réaliser la relève de ces déclarations.</p>
    <p>Pour les utilisateurs ayant un compte de type lycée, le bouton <em>Relever les heures</em> permet de réaliser une relève des déclarations des heures de colles et TD. Cela consiste à marquer comme relevées toutes les heures déclarées, non encore relevées, dont la date d'exécution est située entre le début de l'année et la date donnée dans la case située au-dessus du tableau. Une confirmation sera demandée.</p>
    <p>On ne peut réaliser qu'une relève par jour&nbsp;: si vous faites une deuxième relève, toutes les heures relevées le même jour seront regroupées dans une seule relève.</p>
    <h4>Décomptes des heures</h4>
    <p>L'ensemble des relevés est consigné dans un tableau. Y sont téléchargeables&nbsp;:
    <ul>
      <li>le détail de l'ensemble des notes au format xls, si besoin de vérification, mais a priori non utile</li>
      <li>le décompte des heures au format xls <span class="icon-doc-xls"></span>, suffisant pour mettre au paiement les heures de colles et utile notamment si on veut relier les décomptes de plusieurs classes</li>
      <li>le décompte des heures au format pdf <span class="icon-doc-pdf"></span>, suffisant pour mettre au paiement les heures de colles et utile pour un archivage sur ordinateur</li>
      <li>le décompte des heures en impression directe <span class="icon-voir"></span> (lance l'interface d'impression de votre navigateur), suffisant pour mettre au paiement les heures de colles et utile pour un archivage papier</li>
    </ul>
    <h4>Du côté des colleurs</h4>
    <p>Les colleurs et professeurs ne peuvent plus modifier la durée ni le nombre d'élèves notés pendant les colles marquées comme relevées. Ils peuvent cependant encore modifier les notes, y compris pour les élèves «&nbsp;non notés&nbsp;» ou «&nbsp;absents&nbsp;».</p>
    <p>Chaque colleur/professeur voit en permanence le statut relevé ou non relevé des déclarations qu'il a faites, ainsi que les dates de relève des heures relevées. Les colleurs/professeurs sont donc au courant des heures qui doivent être mises au paiment.</p>
    <h4>Retards de déclaration</h4>
    <p>Le retard de déclaration d'un colleur est sans conséquence&nbsp;: à chaque relève, les heures non encore relevées le sont si elles correspondent à des séances antérieures à la date spécifiée pour la relève, indépendemment des dates des relèves précédentes. Il n'y a pas de régularisation globale à prévoir en juin.</p>
    <h4>Binômes, élèves absents</h4>
    <p>Les textes officiels sont parfaitement clairs&nbsp;: l'heure de colle est indivisible, un binôme doit être payé une heure, au moins pour les matières où une heure est la durée réelle de la colle. C'est pour cela que la durée déclarée par les colleurs peut différer du simple produit nombre d'élèves par durée individuelle. Cela dépend cependant du budget global. Le plus sain est d'avoir débattu le sujet avec l'équipe pédagogique.</p>
    <p>Les colleurs ne saisissent pas la durée des heures de colles, le calcul est réalisé automatiquement suivant deux réglages spécifiques à chaque matière&nbsp;: une durée de colle par élève (20 minutes généralement), et si les colles sont insécables (multiplication directe du nombre d'élèves collés par la durée précédente) ou non (arrondi à l'heure entière supérieure).</p>
    <p>Les élèves absents doivent rattraper leur colle. À défaut, le colleur doit quand-même être payé&nbsp;: c'est pour cela qu'il n'y a pas de différence entre un élève noté et un élève absent dans le décompte.</p>
  </div>
  
  <div id="aide-releve">
    <h3>Aide et explications</h3>
    <p>Ce tableau présente l'état actuel des heures de colles et TD déclarées, non encore relevées. En cas d'absence complète d'heures déclarées ou si toutes les heures ont été relevées, aucun tableau n'est présent et aucune relève n'est bien sûr possible.</p>
    <p>Il est possible de saisir une date maximale, juste au-dessus du tableau, qui est automatiquement remis à jour. Toutes les heures déclarées, non encore relevées, dont la date d'exécution est située entre le début de l'année et la date maximale, sont comptées.</p>
    <p>Pour les utilisateurs ayant un compte de type lycée, le bouton <em>Relever les heures</em> situé sous le tableau permet de réaliser la relève des heures comptées dans le tableau. Cela consiste à marquer comme relevées toutes ces heures, de façon définitive. Une confirmation sera demandée.</p>
    <p>On ne peut réaliser qu'une relève par jour&nbsp;: si vous faites une deuxième relève, toutes les heures relevées le même jour seront regroupées dans une seule relève.</p>
    <h4>Retards de déclaration</h4>
    <p>Le retard de déclaration d'un colleur est sans conséquence&nbsp;: à chaque relève, les heures non encore relevées le sont si elles correspondent à des séances antérieures à la date spécifiée pour la relève, indépendemment des dates des relèves précédentes. Il n'y a pas de régularisation globale à prévoir en juin.</p>
    <h4>Du côté des colleurs</h4>
    <p>Les colleurs et professeurs ne peuvent plus modifier la durée ni le nombre d'élèves notés pendant les colles marquées comme relevées. Ils peuvent cependant encore modifier les notes, y compris pour les élèves «&nbsp;non notés&nbsp;» ou «&nbsp;absents&nbsp;».</p>
    <p>Chaque colleur/professeur voit en permanence le statut relevé ou non relevé des déclarations qu'il a faites, ainsi que les dates de relève des heures relevées. Les colleurs/professeurs sont donc au courant des heures qui doivent être mises au paiment.</p>
  </div>

  <div id="aide-dejareleve">
    <h3>Aide et explications</h3>
    <h4>Décomptes des heures</h4>
    <p>L'ensemble des relevés est consigné dans le tableau présenté ici. Y sont téléchargeables&nbsp;:
    <ul>
      <li>le détail de l'ensemble des notes au format xls, si besoin de vérification, mais a priori non utile</li>
      <li>le décompte des heures au format xls <span class="icon-doc-xls"></span>, suffisant pour mettre au paiement les heures de colles et utile notamment si on veut relier les décomptes de plusieurs classes</li>
      <li>le décompte des heures au format pdf <span class="icon-doc-pdf"></span>, suffisant pour mettre au paiement les heures de colles et utile pour un archivage sur ordinateur</li>
      <li>le décompte des heures en impression directe <span class="icon-voir"></span> (lance l'interface d'impression de votre navigateur), suffisant pour mettre au paiement les heures de colles et utile pour un archivage papier</li>
    </ul>
  </div>

  <script type="text/javascript">
  // Pour l'impression. Récupéré sur https://developer.mozilla.org/en-US/docs/Web/Guide/Printing
  function setPrint () {
    this.contentWindow.__container__ = this;
    this.contentWindow.focus(); // Required for IE
    this.contentWindow.print();
  }

  function printPage (sURL) {
    var oHiddFrame = document.createElement("iframe");
    oHiddFrame.onload = setPrint;
    oHiddFrame.style.position = "fixed";
    oHiddFrame.style.right = "0";
    oHiddFrame.style.bottom = "0";
    oHiddFrame.style.width = "0";
    oHiddFrame.style.height = "0";
    oHiddFrame.style.border = "0";
    oHiddFrame.src = sURL;
    document.body.appendChild(oHiddFrame);
  }
  
  $(function() {
    $('#datemax').datetimepicker({ format: 'd/m/Y', timepicker: false, maxDate: new Date() });
  });
  
  </script>

<?php
fin(true,false,'datetimepicker');
?>
