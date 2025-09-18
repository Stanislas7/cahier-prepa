<?php
// Sécurité : ne doit pas être exécuté s'il n'est pas lancé par un script autorisé
if ( !defined('OK') )  exit();

// Script d'affichage des tableaux statistiques de la page de relève des colles,
// tableaux visibles uniquement des comptes de type lycée et des admins
// Script lancé par relevecolles.php

//////////////
//// HTML ////
//////////////
debut($mysqli,'Relève des déclarations de colles - Statistiques',$message,$autorisation,'relevecolles');
echo <<<FIN
  
  <article>
    <input onclick="location.href='relevecolles'" type="button" class="ligne" value="Relève des déclarations de colles">
    <input onclick="location.href='?stats'" type="button" class="ligne" value="Statistiques par matière et par colleur" disabled>
    <input onclick="location.href='?detail'" type="button" class="ligne" value="Détail de toutes les heures déclarées">
  </article>

FIN;

//////////////////////////////
// Statistiques par matière //
//////////////////////////////
$resultat = $mysqli->query('SELECT m.nom AS matiere, 
                                   SUM(nb*(releve>0)) AS nb_rel, SUM(duree*(releve>0)) AS total_rel, SUM(duree*(releve>0)*(description>\'\')) AS td_rel,
                                   SUM(nb*(releve=0)) AS nb_nrel, SUM(duree*(releve=0)) AS total_nrel, SUM(duree*(releve=0)*(description>\'\')) AS td_nrel
                            FROM heurescolles AS h LEFT JOIN matieres AS m ON matiere = m.id
                            LEFT JOIN (SELECT COUNT(*) AS nb, heure FROM notescolles GROUP BY heure) AS n ON h.id = n.heure
                            GROUP BY m.id ORDER BY ordre');
echo <<<FIN
  <article>
    <h3>Détail des heures déclarées par matière</h3>

FIN;
if ( $resultat->num_rows )  {
  echo <<<FIN
    <table class="centre">
      <tbody>
        <tr><th></th><th colspan="3">Heures relevées</th><th colspan="3">Heures non relevées</th></tr>
        <tr><th>Matière</th><th>Colles (élèves)</th><th>TD</th><th>Total</th><th>Colles (élèves)</th><th>TD</th><th>Total</th></tr>

FIN;
  $total = array('nb_rel'=>0,'td_rel'=>0,'total_rel'=>0,'nb_nrel'=>0,'td_nrel'=>0,'total_nrel'=>0);
  while ( $r = $resultat->fetch_assoc() )  {
    echo "
      <tr><td>${r['matiere']}</td>
          <td>".format_duree_eleves($r['total_rel']-$r['td_rel'],$r['nb_rel']).'</td><td>'.format_duree($r['td_rel']).'</td><td>'.format_duree($r['total_rel']).'</td>
          <td>'.format_duree_eleves($r['total_nrel']-$r['td_nrel'],$r['nb_nrel']).'</td><td>'.format_duree($r['td_nrel']).'</td><td>'.format_duree($r['total_nrel'])."</td></tr>\n";
    foreach ( $total as $i => $v )
      $total[$i] += $r[$i];
  }
  echo "
      <tr><th>Total</th>
          <th>".format_duree_eleves($total['total_rel']-$total['td_rel'],$total['nb_rel']).'</th><th>'.format_duree($total['td_rel']).'</th><th>'.format_duree($total['total_rel']).'</th>
          <th>'.format_duree_eleves($total['total_nrel']-$total['td_nrel'],$total['nb_nrel']).'</th><th>'.format_duree($total['td_nrel']).'</th><th>'.format_duree($total['total_nrel'])."</th></tr>
    </tbody>\n    </table>\n  </article>\n\n";
  $resultat->free();
}
else  {
  echo "<div class=\"annonce\">Il n'y a encore aucune heure de colle déclarée cette année.</div>\n  </article>\n\n";
  $mysqli->close();
  fin(false);
}

//////////////////////////////
// Statistiques par colleur //
//////////////////////////////
$resultat = $mysqli->query('SELECT IF(LENGTH(c.nom),CONCAT(c.nom,\' \',c.prenom),c.login) AS colleur, m.nom AS matiere, 
                                   SUM(nb*(releve>0)) AS nb_rel, SUM(duree*(releve>0)) AS total_rel, SUM(duree*(releve>0)*(description>\'\')) AS td_rel,
                                   SUM(nb*(releve=0)) AS nb_nrel, SUM(duree*(releve=0)) AS total_nrel, SUM(duree*(releve=0)*(description>\'\')) AS td_nrel
                            FROM heurescolles AS h LEFT JOIN utilisateurs AS c ON colleur = c.id LEFT JOIN matieres AS m ON matiere = m.id
                            LEFT JOIN (SELECT COUNT(*) AS nb, heure FROM notescolles GROUP BY heure) AS n ON h.id = n.heure
                            GROUP BY colleur, m.id ORDER BY ordre, c.nom');
$mysqli->close();
echo <<<FIN
  <article>
    <h3>Détail des heures déclarées par colleur</h3>

FIN;
if ( $resultat->num_rows )  {
  echo <<<FIN
    <table class="centre">
      <tbody>
        <tr><th colspan="2"></th><th colspan="3">Heures relevées</th><th colspan="3">Heures non relevées</th></tr>
        <tr><th>Colleur</th><th>Matière</th><th>Colles (élèves)</th><th>TD</th><th>Total</th><th>Colles (élèves)</th><th>TD</th><th>Total</th></tr>

FIN;
  while ( $r = $resultat->fetch_assoc() )
    echo "
      <tr><td>${r['colleur']}</td><td>${r['matiere']}</td>
          <td>".format_duree_eleves($r['total_rel']-$r['td_rel'],$r['nb_rel']).'</td><td>'.format_duree($r['td_rel']).'</td><td>'.format_duree($r['total_rel']).'</td>
          <td>'.format_duree_eleves($r['total_nrel']-$r['td_nrel'],$r['nb_nrel']).'</td><td>'.format_duree($r['td_nrel']).'</td><td>'.format_duree($r['total_nrel'])."</td></tr>\n";
  echo "      </tbody>\n    </table>\n  </article>\n\n";
  $resultat->free();
}

fin(false);
?>
