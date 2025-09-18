<?php
// Sécurité : ne doit pas être exécuté s'il n'est pas lancé par un script autorisé
if ( !defined('OK') )  exit();

// Script de gestion globale des notes de colles, spécifique aux professeurs associés à la matière
// Script lancé par notescolles.php
// Autorisation obligatoirement égale à 5
// Variables $matiere, $mid et $cle déjà réglées

////////////
/// HTML ///
////////////
debut($mysqli,"Listes des notes de colles - ${matiere['nom']}",$message,5,"notescolles?$cle&gestion",array('action'=>'notescollesgestion','matiere'=>$mid,'css'=>'datetimepicker'));

echo <<<FIN
  
  <div id="icones">
    <a class="icon-prefs formulaire" title="Modifier les réglages des notes de colles"></a>
    <a class="icon-aide" title="Aide pour les modifications des notes de colles"></a>
  </div>

  <article>
    <input onclick="location.href='?$cle'" type="button" class="ligne" value="Déclaration des heures de colles">
    <input onclick="location.href='?$cle&amp;gestion'" type="button" class="ligne" value="Statistiques, liste des colles, réglages" disabled>
    <input onclick="location.href='?$cle&amp;tableau'" type="button" class="ligne" value="Tableau de notes téléchargeable">
  </article>
  
FIN;

// Récupération du décompte de la matière
$resultat = $mysqli->query("SELECT c.id AS cid, IF(LENGTH(nom),CONCAT(LEFT(prenom,1),'. ',nom),login) AS cnom,
                                   SUM(duree*(releve>0)) AS total_rel, SUM(duree*(releve>0)*(description>'')) AS td_rel,
                                   SUM(duree*(releve=0)) AS total_nrel, SUM(duree*(releve=0)*(description>'')) AS td_nrel
                            FROM heurescolles AS h LEFT JOIN utilisateurs AS c ON h.colleur=c.id
                            WHERE matiere = $mid GROUP BY c.id ORDER BY nom, prenom, login");
if ( $resultat->num_rows )  {
  echo  <<<FIN
  <article>
    <h3>Statistiques globales</h3>
    <table id="notesstat">
      <tbody>
        <tr><th></th>

FIN;
  $ligne_eleves = $ligne_heures_rel = $ligne_heures_nrel = $ligne_heures = $ligne_moyenne = '';
  $total = array('nb'=>0,'total_rel'=>0,'td_rel'=>0,'total_nrel'=>0,'td_nrel'=>0);
  while ( $r = $resultat->fetch_assoc() )  {
    $resultat2 = $mysqli->query("SELECT COUNT(*) AS nb, LEFT(REPLACE(AVG(IF(note='abs' OR note='nn',NULL,REPLACE(note,',','.'))),'.',','),4) AS moy FROM notescolles WHERE colleur = ${r['cid']} AND matiere = $mid");
    $s = $resultat2->fetch_assoc();
    $resultat2->free();
    echo "          <th class=\"vertical\"><span>${r['cnom']}</span></th>\n";
    $ligne_eleves .= "<td>${s['nb']}</td>";
    $ligne_heures_rel .= '<td>'.format_duree($r['total_rel']).( $r['td_rel'] ? '&nbsp;('.format_duree($r['td_rel']).')' : '' ).'</td>';
    $ligne_heures_nrel .= '<td>'.format_duree($r['total_nrel']).( $r['td_nrel'] ? '&nbsp;('.format_duree($r['td_nrel']).')' : '' ).'</td>';
    $ligne_heures .= '<td>'.format_duree($r['total_rel']+$r['total_nrel']).( ($d=$r['td_rel']+$r['td_nrel']) ? '&nbsp;('.format_duree($d).')' : '' ).'</td>';
    $ligne_moyenne .= "<td>${s['moy']}</td>";
    $total['nb'] += $s['nb'];
    $total['total_rel'] += $r['total_rel'];
    $total['td_rel'] += $r['td_rel'];
    $total['total_nrel'] += $r['total_nrel'];
    $total['td_nrel'] += $r['td_nrel'];
  }
  $resultat->free();
  // Totaux d'heures
  $ligne_heures_rel = ( $total['td_rel'] ? "<tr><th>Nombre d'heures relevées (dont séances sans note)</th>$ligne_heures_rel<td>".format_duree($total['total_rel']).'&nbsp;('.format_duree($total['td_rel']).')</td></tr>'
                                         : "<tr><th>Nombre d'heures relevées</th>$ligne_heures_rel<td>".format_duree($total['total_rel']).'</td></tr>' );
  $ligne_heures_nrel = ( $total['td_nrel'] ? "<tr><th>Nombre d'heures non relevées (dont séances sans note)</th>$ligne_heures_nrel<td>".format_duree($total['total_nrel']).'&nbsp;('.format_duree($total['td_nrel']).')</td></tr>'
                                           : "<tr><th>Nombre d'heures non relevées</th>$ligne_heures_nrel<td>".format_duree($total['total_nrel']).'</td></tr>' );
  $ligne_heures = ( ($d=$total['td_rel']+$total['td_nrel']) ? "<tr><th>Nombre d'heures total (dont séances sans note)</th>$ligne_heures<td>".format_duree($total['total_rel']+$total['total_nrel']).'&nbsp;('.format_duree($d).')</td></tr>'
                                                            : "<tr><th>Nombre d'heures total</th>$ligne_heures<td>".format_duree($total['total_rel']+$total['total_nrel']).'</td></tr>' );
  // Moyenne globale
  $resultat = $mysqli->query("SELECT LEFT(REPLACE(AVG(IF(note='abs' OR note='nn',NULL,REPLACE(note,',','.'))),'.',','),4) FROM notescolles WHERE matiere = $mid");
  $r = $resultat->fetch_row();
  $resultat->free();
  echo <<<FIN
          <th class="vertical"><span>Total</span></th>
        </tr>
        <tr><th>Nombre d'élèves interrogés</th>$ligne_eleves<td>${total['nb']}</td></tr>
        $ligne_heures
        $ligne_heures_rel
        $ligne_heures_nrel
        <tr><th>Moyenne</th>$ligne_moyenne<td>${r[0]}</td></tr>
      </tbody>
    </table>
  </article>
  
FIN;
}

// Récupération de l'ensemble des élèves associés à la matière
// $table sert à construire une table invisible qui sert à la génération du 
// formulaire d'édition, identique à celle générée dans notescolles.php
// $select_eleves sert à réduire l'affichage à un élève
$resultat = $mysqli->query("SELECT e.id, IF(LENGTH(nom),CONCAT(nom,' ',prenom),login) AS nomcomplet, IF(LENGTH(nom),CONCAT(LEFT(prenom,1),'. ',nom),login) AS initiale
                            FROM notescolles AS n LEFT JOIN utilisateurs AS e ON eleve = e.id 
                            WHERE n.matiere = $mid GROUP BY e.id ORDER BY nomcomplet");
$eleves = array();
$table = $select_eleves = '';
while ( $r = $resultat->fetch_assoc() )  {
  $eleves[$r['id']] = $r;
  $table .= "        <tr data-id=\"${r['id']}\"><td>${r['nomcomplet']}</td></tr>\n";
  $select_eleves .= "\n        <option value=\"${r['initiale']}\">${r['nomcomplet']}</option>";
}
$resultat->free();

// Récupération de l'ensemble des colleurs associés à la matière
// Sert à réduire l'affichage à un colleur
$resultat = $mysqli->query("SELECT IF(LENGTH(nom),CONCAT(nom,' ',prenom),login) AS nomcomplet, IF(LENGTH(nom),CONCAT(LEFT(prenom,1),'. ',nom),login) AS initiale
                            FROM heurescolles LEFT JOIN utilisateurs ON colleur = utilisateurs.id 
                            WHERE matiere = $mid GROUP BY utilisateurs.id ORDER BY nomcomplet");
$select_colleurs = '';
while ( $r = $resultat->fetch_assoc() )
  $select_colleurs .= "\n      <option value=\"${r['initiale']}\">${r['nomcomplet']}</option>";
$resultat->free();

// Listes des colles déclarées
$resultat = $mysqli->query("SELECT h.id, IF(LENGTH(nom),CONCAT(LEFT(prenom,1),'. ',nom),login) AS colleur, 
                            DATE_FORMAT(jour,'%d/%m/%y') AS jour, IF(rattrapage,DATE_FORMAT(rattrapage,'%d/%m/%y'),'-') AS rattrapage,
                            duree, original, description, IF(releve,DATE_FORMAT(releve,'%d/%m'),'-') AS releve
                            FROM heurescolles AS h LEFT JOIN utilisateurs AS c ON colleur = c.id
                            WHERE matiere = $mid ORDER BY h.jour DESC".( isset($_REQUEST['voirtout']) ? '' : ' LIMIT 30' ));
// Affichage
if ( $n = $resultat->num_rows )  {
  if ( isset($_REQUEST['voirtout']) || ( $n < 30 ) )  {
    $titre = 'Liste de toutes les colles';
    $icone = '';
  }
  else  {
    $titre = 'Liste des dernières colles';
    $icone = "<a class=\"icon-voirtout\" href=\"?$cle&amp;gestion&amp;voirtout\"></a>\n    ";
  }
  echo  <<<FIN
  <article>
    $icone<h3>$titre</h3>
    <p class="ligne">
      <label for="colleurs">Afficher un colleur&nbsp;:</label>
      <select id="colleurs" onchange="$('#notes tr').show(); $('#colleurs,#eleves').each( function() { if ( this.value != '0' ) $('#notes tr:not(:first)').not(':contains(&quot;'+this.value+'&quot;)').hide(); });">
        <option value="/">tous les colleurs</option>$select_colleurs
      </select>
    </p>
    <p class="ligne">
      <label for="eleves">Afficher un élève&nbsp;:</label>
      <select id="eleves" onchange="$('#notes tr').show(); $('#colleurs,#eleves').each( function() { if ( this.value != '0' ) $('#notes tr:not(:first)').not(':contains(&quot;'+this.value+'&quot;)').hide(); });">
        <option value="/">tous les élèves</option>$select_eleves
      </select>
    </p>
    <table id="notes">
      <tbody>
        <tr><th>Colleur</th><th>Jour</th><th>Rattrapage</th><th>Élèves (notes) ou Description</th><th>Durée</th><th>Relève</th><th></th></tr>

FIN;
  // Affichage de chaque heure
  while ( $r = $resultat->fetch_assoc() )  {
    $duree = format_duree($r['duree']);
    $data = $original = '';
    $texte = $r['description'];
    $supprime = ( strlen($r['releve']) > 1 ) ? '<span>&nbsp;</span>' : '<a class="icon-supprime" title="Supprimer cette colle"></a>';
    if ( strlen($r['releve']) == 1 )
      $duree = "<span data-id=\"${r['id']}\" class=\"editable duree\" data-champ=\"duree\">$duree</span>";
    $voir = $mail = '<span>&nbsp;</span>';
    // Cas des colles classiques
    if ( !$texte )  {
      $resultat1 = $mysqli->query("SELECT semaine, eleve, note, commentaire FROM notescolles WHERE heure = ${r['id']}");
      $texte = $eids = $notes = array();
      while ( $r1 = $resultat1->fetch_assoc() )  {
        $eids[] = $e = $r1['eleve'];
        $notes[] = $r1['note'];
        if ( $r1['commentaire'] )  {
          $voir = '<a class="icon-comms" title="Voir les commentaires"></a>';
          $texte[$eleves[$e]['nomcomplet']] = "<span><u>{$eleves[$e]['initiale']}</u> (${r1['note']})</span>";
        }
        else
          $texte[$eleves[$e]['nomcomplet']] = "<span>{$eleves[$e]['initiale']} (${r1['note']})</span>";
        $semaine = $r1['semaine'];
      }
      // Mise en évidence des colles dont la durée originale a été modifiée
      if ( $r['original'] != $r['duree'] ) 
        $original = ' class="nooriginal" title="Valeur originale : '.format_duree($r['original']).'"';
      // Tri alphabétique pour l'affichage
      ksort($texte);
      $texte = implode(', ',$texte);
      $data = ' data-eleves="'.implode('|',$eids).'" data-notes="'.implode('|',$notes)."\" data-sid=\"$semaine\"";
      $mail = '<a class="icon-mail" href="mail?enr_dests&uids='.implode(',',$eids).'" title=\"Envoyer un mail aux élèves"></a>';
    }
    // Affichage
    echo <<<FIN
        <tr>
          <td>${r['colleur']}</td><td>${r['jour']}</td><td>${r['rattrapage']}</td><td>$texte</td><td$original>$duree</td><td>${r['releve']}</td>
          <td class="icones" data-id="${r['id']}">
            $voir
            $mail
            <a class="icon-edite formulaire"$data title="Éditer cette colle"></a>
            $supprime
          </td>
        </tr>

FIN;
  }
  $resultat->free();
  echo <<<FIN
      </tbody>
    </table>
  </article>

FIN;
}
$mysqli->close();

// Aide et formulaire de modification
?>

  <form id="form-edite">
    <h3 class="edition">Modifier des notes</h3>
    <p class="ligne"><label for="colleur">Colleur&nbsp;:</label><input type="text" name="colleur" value="" size="8" disabled></p>
    <p class="ligne"><label for="jour">Jour dans le colloscope&nbsp;:</label><input type="text" name="jour" value="" size="8"></p>
    <p class="ligne"><label for="rattrapage">Jour de rattrapage si différent&nbsp;:</label><input type="text" name="rattrapage" value="" size="8"></p>
    <p class="ligne"><label for="duree">Durée&nbsp;:</label><input type="text" name="duree" value="" size="4" value="0"></p>
    <table class="notes"></table>
    <p class="ligne"><label for="description">Description&nbsp;: </label><input type="text" name="description" value="" size="100" placeholder="Description de la séance (obligatoire)"></p>
  </form>

  <form id="form-notes">
    <table>
      <tbody>
<?php echo $table; ?>
      </tbody>
    </table>
    <div><select><option value="x"></option><option value="10">10</option><option value="11">11</option><option value="12">12</option><option value="13">13</option><option value="14">14</option><option value="15">15</option><option value="9">9</option><option value="8">8</option><option value="7">7</option><option value="6">6</option><option value="abs">Absent</option><option value="nn">Non noté</option><option value="16">16</option><option value="17">17</option><option value="18">18</option><option value="19">19</option><option value="20">20</option><option value="5">5</option><option value="4">4</option><option value="3">3</option><option value="2">2</option><option value="1">1</option><option value="0">0</option><option value="0,5">0,5</option><option value="1,5">1,5</option><option value="2,5">2,5</option><option value="3,5">3,5</option><option value="4,5">4,5</option><option value="5,5">5,5</option><option value="6,5">6,5</option><option value="7,5">7,5</option><option value="8,5">8,5</option><option value="9,5">9,5</option><option value="10,5">10,5</option><option value="11,5">11,5</option><option value="12,5">12,5</option><option value="13,5">13,5</option><option value="14,5">14,5</option><option value="15,5">15,5</option><option value="16,5">16,5</option><option value="17,5">17,5</option><option value="18,5">18,5</option><option value="19,5">19,5</option></select></div>
  </form>

  <form id="form-prefs" data-action="prefsmatiere">
    <h3 class="edition">Réglages des notes de colles en <?php echo $matiere['nom']; ?></h3>
    <p>Il n'est pas possible de désactiver cette fonction ici. Vous pouvez le faire depuis la page de <a href="matieres">gestion des matières</a>.</p>
    <p>Il n'est pas possible de modifier les semaines correspondant à des notes de colle ou les utilisateurs (élèves comme colleurs) concernés par cette matière. Il faut passer par les pages d'administration pour cela.</p>
    <p>Les durées des colles sont calculées automatiquement (et modifiables dans le tableau ci-dessous). Les paramètres de calcul sont&nbsp;:</p>
    <p class="ligne"><label for="dureecolles">Durée des colles en minutes par élève&nbsp;: </label><input type="text" name="dureecolles" placeholder="Valeur par défaut. Typiquement 20 ou 30." value="<?php echo $matiere['dureecolles']; ?>" size="3"></p>
    <p class="ligne"><label for="heurescolles">Heure de colle insécable&nbsp;: </label><input type="checkbox" name="heurescolles" value="1"<?php echo $matiere['heurescolles'] ? ' checked' : ''; ?>></p>
  </form>

  <div id="aide-notescollesgestion">
    <h3>Aide et explications</h3>
    <p>Il est possible ici de consulter et modifier les notes et heures de colles que vous et vos colleurs avez déclarées et de modifier les préférence de calcul automatique des durées de colles.</p>
    <h4>Taille du tableau</h4>
    <p>Le tableau est a priori limité à 30 lignes, mais le bouton <span class="icon-voirtout"></span> permet de visualiser l'ensemble des saisies depuis le début de l'année.</p>
    <h4>Modification des réglages du calcul automatique de durée</h4>
    <p>Le bouton <span class="icon-prefs"></span> permet de modifier deux propriétés spécifiques à la matière&nbsp;: les réglages du calcul automatique de durée des colles.</p>
    <p>Si le lycée utilise les saisies de notes de colles/séances sans note pour mettre au paiement les heures de colle, les durées déclarées comptent particulièrement. Pour les colles avec notes, cette durée est calculée automatiquement lors de la saisie à l'aide de deux réglages spécifiques à chaque matière qui spécifient le nombre de minutes par élève et si l'heure de colle est indivisible (un binôme = une heure même si un élève = 20 minutes) ou non (comptage des minutes sans arrondi à l'heure).</p>
    <p>Attention, les textes officiels précisent que chaque heure de colle est normalement indivisible, mais la question du budget global peut modifier cela. Il est nécessaire que l'ensemble de l'équipe pédagogique soit coordonnée sur ce point. L'administration a accès au détail des notes et au nombre d'élèves collés.</p>
    <p>La durée d'une colle est immédiatement calculée, à la saisie. Modifier les paramètres de calcul ne modifie pas les durées déjà calculées, mais seulement celles qui seront déclarées dans l'avenir. Il est possible de régler 20 minutes par élève pendant l'année et 30 pendant les préparations à l'oral par exemple.</p>
    <h4>Action spécifique à chaque colle</h4>
    <p>Chaque colle ou séance sans note saisie par vous-mêmes ou par vos colleurs correspond à une ligne du tableau récapitulatif. Vous pouvez, à l'aide des boutons à droite de chaque ligne&nbsp;:</p>
    <ul>
      <li><span class="icon-edite"></span>&nbsp;: modifier la saisie. La date de la colle ne peut être déplacée en dehors de la semaine initialement saisie. Les notes sont modifiables. Si la colle n'a pas déjà été relevée par l'administration, la durée est aussi modifiable.</li>
      <li><span class="icon-montre"></span>&nbsp; montrer les commentaires de colle. Ce bouton n'apparaît que sur les lignes correspondant à des colles où des commentaires ont été saisis, marqués par un soulignement des élèves concernés.</li>
      <li><span class="icon-supprime"></span>&nbsp;: supprimer la colle ou la séance de note. Ce n'est possible que si la colle n'a pas déjà été relevée par l'administration du lycée.</li>
    </ul>
    <h4>Modification unique d'une durée</h4>
    <p>Vous pouvez ici modifier directement les durées saisies par vous-même ou par vos colleurs à l'aide du bouton <span class="icon-edite"></span> à côté de chaque durée. Ces durées ont été calculées automatiquement et l'erreur est possible.</p>
    <p>Ce bouton n'est affiché que pour les colles qui n'ont pas encore été relevées par l'administration du lycée.</p>
    <h4>Modification globale des saisies</h4>
    <p>Le bouton <span class="icon-edite"></span> situé à droite de chaque ligne du tableau ci-dessous permet de modifier plus globalement la colle&nbsp;: dates, notes données ou description pour les séances sans note.</p>
    <p>La durée est aussi modifiable, uniquement si la colle n'a pas déjà été relevée par l'administration du lycée.</p>
    <p>Il n'est pas possible de modifier la liste des élèves collés (d'ajouter ou supprimer des notes).</p>
    <p>En «&nbsp;semaine de colles classiques&nbsp;», un élève ne peut avoir qu'une seule note par matière et par semaine. En «&nbsp;semaine de préparation à l'oral&nbsp;», cette limitation n'existe plus. Afin d'éviter les risques de collision, le jour de la colle est contraint de rester sur la semaine où il est déjà inscrit. S'il s'agit d'une erreur, il faut le signaler au colleur qui devra supprimer puis recommencer sa saisie. Le réglage du type de colles est réalisable par les utilisateurs ayant les droits d'administration du Cahier, sur la page de <a href="planning">gestion du planning</a>.</p>
    <p>Si la colle est réalisée au <em>jour</em> prévu par le colloscope, une seule date doit être saisie. Si la colle a été déplacée, il est important que le <em>jour dans le colloscope</em> saisi soit bien la date initialement prévue, et que le <em>jour de rattrapage</em> soit la date effective de la colle. Les élèves ne peuvent pas avoir deux notes sur une même semaine dans une même matière.</p>
    <h4>Visualisation des commentaires</h4>
    <p>Les élèves pour lesquels un commentaire de colle a été écrit par le colleur sont soulignés. Le bouton <span class="icon-montre"></span> apparaît alors sur la ligne, et un clic dessus permet d'afficher ces commentaires.</p>
  </div>

  <div id="aide-edite">
    <h3>Aide et explications</h3>
    <p>Ce formulaire permet de modifier des notes de colle (ou des séances sans note). Il sera validé par un clic sur <span class="icon-ok"></span>, et abandonné (donc supprimé) par un clic sur <span class="icon-ferme"></span>.</p>
    <p>En «&nbsp;semaine de colles classiques&nbsp;», un élève ne peut avoir qu'une seule note par matière et par semaine. En «&nbsp;semaine de préparation à l'oral&nbsp;», cette limitation n'existe plus. Afin d'éviter les risques de collision, le jour de la colle est contraint de rester sur la semaine où il est déjà inscrit. S'il s'agit d'une erreur, il faut le signaler au colleur qui devra supprimer puis recommencer sa saisie. Le réglage du type de colles est réalisable par les utilisateurs ayant les droits d'administration du Cahier, sur la page de <a href="planning">gestion du planning</a>.</p>
    <p>Si la colle est réalisée au <em>jour</em> prévu par le colloscope, une seule date doit être saisie. Si la colle a été déplacée, il est important que le <em>jour dans le colloscope</em> saisi soit bien la date initialement prévue, et que le <em>jour de rattrapage</em> soit la date effective de la colle. Les élèves ne peuvent pas avoir deux notes sur une même semaine dans une même matière.</p>
    <p>La <em>durée</em> est calculée automatiquement lors de la saisie. Vous pouvez modifier ici cette valeur, uniquement si la colle n'a pas déjà été relevée par l'administration du lycée.</p>
    <p>Il n'est pas possible de modifier la liste des élèves collés (d'ajouter ou supprimer des notes).</p>
    <p>Les notes de colles ou la description de la séance sans note sont modifiables.</p>
  </div>

  <div id="aide-prefs">
    <h3>Aide et explications</h3>
    <p>Ce formulaire permet de modifier deux propriétés spécifiques à la matière&nbsp;: les réglages du calcul automatique de durée des colles. Il sera validé par un clic sur <span class="icon-ok"></span>, et abandonné (donc supprimé) par un clic sur <span class="icon-ferme"></span>.</p>
    <p>Si le lycée utilise les saisies de notes de colles/séances sans note pour mettre au paiement les heures de colle, les durées déclarées comptent particulièrement. Pour les colles avec notes, cette durée est calculée automatiquement lors de la saisie à l'aide de deux réglages spécifiques à chaque matière qui spécifient le nombre de minutes par élève et si l'heure de colle est indivisible (un binôme = une heure même si un élève = 20 minutes) ou non (comptage des minutes sans arrondi à l'heure).</p>
    <p>Attention, les textes officiels précisent que chaque heure de colle est normalement indivisible, mais la question du budget global peut modifier cela. Il est nécessaire que l'ensemble de l'équipe pédagogique soit coordonnée sur ce point. L'administration a accès au détail des notes et au nombre d'élèves collés.</p>
    <p>Ces réglages sont spécifiques à chaque matière. Pour visualiser les réglages adoptés par les autres matières, vous pouvez aller à la page de <a href="matieres">gestion des matières</a>.</p>
    <p>La durée d'une colle est immédiatement calculée, à la saisie. Modifier les paramètres de calcul ne modifie pas les durées déjà calculées, mais seulement celles qui seront déclarées dans l'avenir. Il est possible de régler 20 minutes par élève pendant l'année et 30 pendant les préparations à l'oral par exemple.</p>
  </div>

<?php
fin(true,false,'datetimepicker');
?>
