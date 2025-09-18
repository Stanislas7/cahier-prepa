/*//////////////////////////////////////////////////////////////////////////////
Éléments JavaScript pour l'utilisation de base de Cahier de Prépa

* Les fonctions communes aux visualisations avec ou sans droit d'édition sont
disponibles dans commun.js : ajaxSend, affiche, popup, reconnect
//////////////////////////////////////////////////////////////////////////////*/

////////////////////////////////////////////////////////////////////////////
// Modification des éléments (nécessite le chargement complet de la page) //
////////////////////////////////////////////////////////////////////////////
$( function() {

  ////////////////////////////////////////
  // Transferts de documents personnels //
  ////////////////////////////////////////

  // Envoi de documents pour les élèves (Transfert de copies)
  $('.transfert button.icon-ok').on("click", function(e) {
    e.preventDefault();
    // Test de connexion
    // Si reconnect() appelée, le paramètre connexion sert à obtenir un retour
    // en état ok/nok pour affichage si nok. Si ok, on réécrit le message. 
    $.ajax({url: 'ajax.php',
            method: "post",
            data: 'verifconnexion=1',
            dataType: 'json',
            el: $(this),
            fonction: function(el) {
              // Envoi réel du fichier ou des données
              var form = el.parent().parent();
              var data = new FormData(form[0]);
              data.append('id',form.parent().data('id'));
              // Envoi
              $.ajax({url: 'ajax.php',
                      xhr: function() { // Évolution du transfert
                        var xhr = $.ajaxSettings.xhr();
                        $('#log').hide(0);
                        if ( xhr.upload && ( el.prev()[0].files.length > 0 ) )  {
                          $('#load').html('<p>Transfert en cours<span></span></p><img src="js/ajax-loader.gif">');
                          var bg = $('#load p').css('background');
                          var pourcent = 0;
                          xhr.upload.addEventListener('progress',function(e) {
                            if (e.lengthComputable)  {
                              pourcent = Math.round(e.loaded / e.total * 100);
                              $('#load span').html(' - ' + pourcent + '%');
                              $('#load p').css('background',bg.replace(/0%/g, pourcent+'%'));
                            }
                          }, false);
                        }
                        return xhr;
                      },
                      method: "post",
                      data: data,
                      dataType: 'json',
                      contentType:false,
                      processData:false
              });
            }
    });
  });

  // Téléchargement des documents en cliquant sur le nom
  $('.transfert a span').parent().css('cursor','pointer').on("click", function() {
    $(this).siblings('.icon-download').click();
  });

  // Bouton de téléchargement d'un document
  $('.transfert a.icon-download').on("click",function() {
    // Test de connexion : on fait le téléchargement en get, donc on doit
    // être connecté en connection non light avant
    $.ajax({url: 'ajax.php',
            method: "post",
            data: 'verifconnexion=1',
            dataType: 'json',
            el: $(this).parent(),
            fonction: function(el) {
              $('#log').hide(0);
              window.location.href = 'transferts.php?dl=' + el.data('id') + '&t=' + el.parent().data('id') + '&verif=' + el.data('verif');
            }
    });
  });

  // Bouton de suppression d'un document
  $('.transfert a.icon-supprime').on("click", function() {
    var ligne = $(this).parent();
    // Demande de confirmation
    popup('<h3>Demande de confirmation</h3><p>Vous allez supprimer un document que vous avez envoyé. Vos professeurs/colleurs qui pouvaient le voir ne pourront plus le récupérer après cela. Cette action n\'est pas annulable.</p><p class="confirmation"><button class="icon-ok"></button>&nbsp;&nbsp;&nbsp;<button class="icon-annule"></button></p>',true);
    $('#fenetre .icon-ok').on("click", function () {
      // Envoi
      $.ajax({url: 'ajax.php',
              method: "post",
              data: { action:'suppr-transdocs', id:ligne.data('id'), transfert: ligne.parent().data('id') },
              dataType: 'json',
              el: ligne,
              fonction: function(el) {
                el.remove();
              }
      });
      $('#fenetre,#fenetre_fond').remove();
    });
    $('#fenetre .icon-annule').on("click",function () {
      $('#fenetre,#fenetre_fond').remove();
    });
  });

  ///////////////////////////////////////////////  
  // Téléchargement du contenu d'un répertoire //
  ///////////////////////////////////////////////  
  $('#parentsdoc a.icon-downloadrep').on("click", function() {
    // Suppression d'un éventuel contenu épinglé existant
    $('#epingle').remove();
    // Création du nouveau formulaire
    $('<article id="epingle"><a class="icon-ferme" title="Fermer"></a><a class="icon-download" title="Valider"></a>\
       <h3 class="edition">Télécharger le contenu d\'un répertoire</h3>\
       <form><table><thead><tr><th>Nom</th><th class="icones"><a class="icon-cocher" title="Tout cocher"></a></th></tr></thead><tbody></tbody></table>\
       </form></article>').prependTo($('section')).append($('#aide-download').html());
    var table = $('#epingle table');
    $('section > p[data-id]').each(function() {
      var el = $(this);
      if ( el.hasClass('rep') )
        table.append('<tr><td><span class="icon-rep"></span>&nbsp;'+el.find('span.nom').text()+'</td><td class="icones"><input type="checkbox" name="reps[]" value="'+el.data('id')+'"></td></tr>');
      else
        table.append('<tr><td><span class="icon-doc"></span>&nbsp;'+el.find('span.nom').text()+'</td><td class="icones"><input type="checkbox" name="docs[]" value="'+el.data('id')+'"></td></tr>');
    });
    // Si utilisateur non connecté : il faut le laisser passer sur recup.php
    if ( $('#iconesmenu .icon-connexion').length )
      $('#epingle form').append('<input type="hidden" name="auto0" value="1">');

    // Modifications des cases du tableau
    $('input',table).on("change",function() {
      $(this).parent().parent().toggleClass('sel',this.checked);
    });
    $('.icon-cocher',table).on("click",function() {
      $(this).toggleClass('icon-cocher icon-decocher');
      $('input',table).prop('checked',$(this).hasClass('icon-decocher')).change();
    });
    $('tr',table).find('td:not(:last-child)').on("click",function() {
      $(this).parent().find('input').click().change();
    });
    
    // Bouton de fermeture
    $('#epingle .icon-ferme').on("click",function() { $('#epingle').remove(); });
    // Bouton de validation
    $('#epingle .icon-download').on("click",function() {
      if ( $('input:checked',table).length == 0 ) {
        affiche('<p>Aucune case n\'est cochée.</p>','nok');
        return
      }
      // Récupération des données
      $('body').data('async',true);
      affiche('Récupération de la liste des documents','ok');
      var id = $('#parentsdoc a.icon-downloadrep').data('id');
      $.ajax({url: 'recup.php',
              method: "post",
              data: 'action=download-rep&id='+id+'&'+$('#epingle form').serialize(),
              dataType: 'json',
              afficheform: async function(data) {
                // On n'affiche rien en réalité ici
                var ids = data['dids'].split(',');
                var verifs = data['verifs'].split(',');
                var total = data['taille'];
                if ( ids.length )  {
                  $('#log').hide(0);
                  $('#load').html('<p>Téléchargement en cours<span></span></p><img src="js/ajax-loader.gif">').show(0);
                  var fichiers = [];
                  var recu = 0;
                  // Téléchargement des fichiers avec suivi de la quantité téléchargée
                  try {
                    var bg = $('#load p').css('background');
                    var pourcent = 0;
                    for ( var i = 0 ; i < ids.length ; i++ ) {
                      fichiers[i] = await fetch('download?zip&r='+id+'&d='+ids[i]+'&verif=' + verifs[i]).then(async function(response) {
                        var reader = response.body.getReader();
                        var chunks = [];
                        while (true) {
                          var { done, value } = await reader.read();
                          if (done) break;
                          chunks.push(value);
                          recu += value.length;
                          pourcent = Math.min(100,Math.round(recu / total * 100));
                          $('#load span').html(' - ' + pourcent + '%');
                          $('#load p').css('background',bg.replace(/0%/g, pourcent+'%'));
                        }
                        if ( response.headers.get('Content-Length') === '0' ) return
                        return {name: decodeURIComponent(response.headers.get('Content-Disposition').split('="')[1]).slice(0,-1), input: new Blob(chunks)}
                      });
                    }
                    $('#load span').html(' - 100%');
                    $('#load p').css('background',bg.replace(/0%/g,'100%'));
                    // Construction de l'url blob permettant de simuler le zip à récupérer
                    var blob = await downloadZip(fichiers).blob();
                    var link = document.createElement("a");
                    link.href = URL.createObjectURL(blob);
                    link.download = data['nom'] + '.zip';
                    $('#load').fadeOut();
                    $('body').data('async',false);
                    link.click();
                    URL.revokeObjectURL(link.href);
                  }
                  catch(error) {
                    affiche('Il y a eu une erreur pendant le téléchargement. Vous devriez prévenir l\'administrateur. Le message d\'erreur est « '+error.message+' »','nok');
                    $('#load').fadeOut();
                    $('body').data('async',false);
                  }
                }
                else  {
                  affiche('Il n\'y a rien à télécharger par ici.','nok');
                  $('#load').fadeOut();
                  $('body').data('async',false);
                }
              }
      });
    });

    // Déplacement initial du viewport
    $('#epingle').deplace_viewport();
  });

});
