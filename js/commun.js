/*//////////////////////////////////////////////////////////////////////////////
Éléments JavaScript pour l'utilisation de base de Cahier de Prépa
//////////////////////////////////////////////////////////////////////////////*/

// Change le thème clair ou sombre. Mode peut prendre deux valeurs : light ou dark
function changeTheme(theme)  {
  var m = document.styleSheets[0].rules[1].media;
  m.appendMedium('original');
  if (theme == 'light')  {
    if (m.mediaText.includes('light'))  m.deleteMedium('(prefers-color-scheme: light)');
    if (m.mediaText.includes('dark'))   m.deleteMedium('(prefers-color-scheme: dark)');
  }
  else  {
    m.appendMedium('(prefers-color-scheme: light)');
    m.appendMedium('(prefers-color-scheme: dark)');
  }
  if (theme != themeOS)
    localStorage.setItem('theme', theme);
  else
    localStorage.removeItem('theme');
}
// Thème initial : celui du système ou celui réglé précédemment
var themeOS = ( window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ) ? 'dark' : 'light';
var themeLocal = localStorage.getItem('theme');
if ( themeLocal && (themeOS != themeLocal) )
  changeTheme(themeLocal);

// Notification de résultat de requête AJAX
function affiche(message,etat) {
  // Div d'affichage des résultats des requêtes AJAX
  if ( !$('#log').length )
    $('<div id="log"></div>').appendTo('body').hide(0).on("click", function() { $(this).fadeOut(800); });
  $('#log').removeClass().addClass(etat).html(message).append('<span class="icon-ferme"></span>').fadeIn().off("click").on("click",function() {
    window.clearTimeout(extinction);
    $(this).fadeOut(800);
  });
  extinction = window.setTimeout(function() { $('#log').fadeOut(800); },6000);
}

// Demande de reconnexion si connexion perdue 
// settings : paramètres du premier envoi ajax auquel le serveur a répondu login ou mdp
// light : true si connexion par cookie à compléter (mdp seul), false si connexion
//         complète nécessaire (login,mdp)
function reconnect(settings,light) {
  // Suppression d'une éventuelle fenêtre de la fonction popup existante
  $('#fenetre,#fenetre_fond').remove();
  var action = 'valider cette action';
  // Utile pour le mode édition seulement
  if ( settings.url == 'recup.php' )
    switch ( settings.data['action'] )  {
      case 'prefs':              action = 'récupérer les préférences de cet utilisateur'; break;
      case 'docs' :              action = 'récupérer la liste des répertoires et documents disponibles'; break;
      case 'transdocs':          action = 'actualiser la liste des documents disponibles'; break;
      case 'commentairescolles': action = 'récupérer les commentaires de cette colle';
    }
  else
    // Pour ne pas créer d'erreur si afficheform n'existe pas
    // afficheform est la fonction d'affichage du formulaire dans le cas de 
    // récupération de données sur recup.php
    settings.afficheform = Function.prototype ;
  // Création de la fenêtre d'indentification
  if ( light )
    popup('<a class="icon-ok" title="Valider"></a><h3>Connexion nécessaire</h3>\
           <p>Votre connexion est active, mais vous devez saisir de nouveau votre mot de passe pour '+action+'.</p>\
           <form>\
           <p class="ligne"><label for="motdepasse">Mot de passe&nbsp;: </label><input type="password" name="motdepasse" id="motdepasse"></p>\
           </form>',true);
  else
    popup('<a class="icon-ok" title="Valider"></a><h3>Connexion nécessaire</h3>\
           <p>Votre connexion a été automatiquement désactivée. Vous devez vous connecter à nouveau pour '+action+'.</p>\
           <form>\
           <p class="ligne"><label for="login">Identifiant&nbsp;: </label><input type="text" name="login" id="login"></p>\
           <p class="ligne"><label for="motdepasse">Mot de passe&nbsp;: </label><input type="password" name="motdepasse" id="motdepasse"></p>\
           </form>',true);
  $('#fenetre input').entreevalide($('#fenetre form')).first().focus();

  // Envoi (les données déjà envoyées settings.data ont été automatiquement sérialisées)
  $('#fenetre a.icon-ok').on("click",function () {
    $.ajax({url: settings.url,
            method: "post",
            data: $("#fenetre form").serialize()+'&'+settings.data,
            dataType: 'json',
            el: settings.el,
            afficheform: settings.afficheform,
            fonction: settings.fonction
    }).done( function(data) {
      // Si erreur d'identification, on reste bloqué là
      if ( data['etat'] != 'mdpnok' )
        $('#fenetre,#fenetre_fond').remove();
     });
  });
  // À la suppression : modification du comportement par défaut, ajout d'une notification
  $('#fenetre a.icon-ferme').off("click").on("click",function () {
    affiche('Modification non effectuée, connexion nécessaire','nok');
  });
}

// Affichage par-dessus le contenu de la page
// * contenu : chaine en HTML qui sera utilisée comme contenu de la fenêtre
// * modal : si true, fenêtre modale (impossible de continuer à éditer la page)
function popup(contenu,modal) {
  // Suppression d'une éventuelle fenêtre de la fonction popup existante
  $('#fenetre,#fenetre_fond').remove();
  // Création
  var el = $('<article id="fenetre"></article>').appendTo('body').html(contenu).focus();
  if ( modal )
    $('<div id="fenetre_fond"></div>').appendTo('body').click(function() {
      $('#fenetre,#fenetre_fond').remove();
    });
  // Si fenêtre non modale, possibilité de l'épingler en haut de la page
  else
    $('<a class="icon-epingle" title="Épingler à la page"></a>').prependTo(el).on("click",function() {
      $('#fenetre_fond').remove();
      $(this).remove();
      el.removeAttr('id').prependTo($('section'));
    });
  // Bouton de fermeture
  $('<a class="icon-ferme" title="Fermer"></a>').prependTo(el).on("click",function() {
    el.remove();
    $('#fenetre_fond').remove();
  });
}

///////////////////
// Requêtes AJAX //
///////////////////
$(document).ajaxSend( function(ev,xhr,settings) {
              $('#load').fadeIn(200);
              if ( settings['attente'] )
                $('#load').prepend('<p class="clignote">'+settings['attente']+'</p>');
              // Sécurité anti XSS : Ajout du token CSRF
              if ( $('body').data('csrf-token') )  {
                // Cas des données en array
                if ( settings.data.append ) {
                  if ( !settings.data['csrf-token'] )
                    settings.data.append('csrf-token',$('body').data('csrf-token'));
                }
                else if ( settings.data.indexOf('csrf') == -1 )
                  settings.data = 'csrf-token='+$('body').data('csrf-token')+'&'+settings.data;
              }
            })
           .ajaxStop( function() {
              // Ne pas cacher $('#load') si fetch (qui est asynchone) : on met
              // un marqueur sur le body avant le lancement de la fonction.
              if ( !$('body').data('async') )
                $('#load').fadeOut();
            })
           .ajaxSuccess( function(ev,xhr,settings) {
              if ( settings['attente'] )
                $('#load p').remove();
              var data = xhr.responseJSON;
              if ( typeof data === "undefined" )
                return 
              switch ( data['etat'] ) {
                // Si ok, on l'affiche dans le log et on lance la "fonction" de mise à jour de l'affichage
                case 'ok':
                  $('.nepassortir').removeClass('nok nepassortir');
                  affiche(data['message'],'ok');
                  // Rechargement : si reload vaut 1, on garde la page où elle est
                  if ( data['reload'] )
                    location.reload(data['reload']>1);
                  else
                    settings.fonction(settings.el);
                  // Rechargement de MathJax si déjà chargé sur cette page
                  if ( typeof MathJax != 'undefined' )
                    MathJax.Hub.Queue(["Typeset",MathJax.Hub]);
                  break;
                // Si non ok, on l'affiche dans le log et on ne fait rien de plus
                case 'nok':
                  affiche(data['message'],'nok');
                  // Si fetch (qui est asynchone) : on enlève le marqueur sur body
                  if ( $('body').data('async') )  {
                    $('body').data('async',false);
                    $('#load').fadeOut();
                  }
                  break;
                // Si 'login' : il faut se reconnecter
                // Si 'mdp' : il faut compléter une connexion light obtenue par cookie
                case 'login':
                case 'mdp':
                  reconnect(settings,data['etat']=='mdp');
                  break;
                // Si 'recupok' : récupération de données
                case 'recupok':
                  settings.afficheform(data);
              }
            });

///////////////////////////
// Interface utilisateur //
///////////////////////////

// Blocage du changement de page si cette fonction est appliquée sur l'élément
$.fn.bloque = function()  {
  return $(this).one('change',function(e) { 
    $(this).addClass('nepassortir');
    // Marque sur le faux select pour les select multiples
    $(this).filter('[multiple]').prev().addClass('nepassortir');
    // Marque sur le label si présent
    $(this).parent('p').find('label').addClass('nepassortir');
  });
}

// Envoi par appui sur Entrée
$.fn.entreevalide = function(form) {
  return $(this).on('keypress',function (e) {
    // Action par défaut : on cherche l'icone de validation dans le form
    // et on clique, ou on soumet le form (fonction à définir)
    if ( e.which == 13 ) {
      if ( form.find('a.icon-ok').length == 1 )
        form.find('a.icon-ok').click();
      else if ( form.parent().find('a.icon-ok').length == 1 )
        form.parent().find('a.icon-ok').click();
      else
        form.submit();
      return false;
    }
  });
}

// Déplacement du viewport pour affichage d'un élément si besoin
$.fn.deplace_viewport = function() {
  if ( this[0].getBoundingClientRect().top < 0 || this[0].getBoundingClientRect().bottom > window.innerHeight )
    $('html, body').animate({ scrollTop: $(this).offset().top-30});
  return $(this);
}

////////////////////////////////////////////////////////////////////////////
// Modification des éléments (nécessite le chargement complet de la page) //
////////////////////////////////////////////////////////////////////////////
$( function() {

  // Connexion
  $('a.icon-connexion').on('click',function(e) {
    // Suppression du menu si visible en mode mobile
    if ( $('#menu').hasClass('visible') )
      $('.icon-menu').click();
    // Création de fenêtre
    popup('<a class="icon-ok" title="Valider"></a><h3>Connexion</h3>\
<form>\
  <p>Veuillez entrer votre identifiant et votre mot de passe&nbsp;:</p>\
  <input class="ligne" type="text" name="login" placeholder="Identifiant">\
  <input class="ligne" type="password" name="motdepasse" placeholder="Mot de passe">\
  <p class="souvenir"><label for="permconn">Se souvenir de moi</label><input type="checkbox" name="permconn" id="permconn" value="1"></p>\
</form>\
<p class="oubli"><a href="gestioncompte?oublimdp">Identifiant ou mot de passe oublié&nbsp;?</a></p>',true);
    // Récupération de la possibilité de création de compte
    $.ajax({url: 'recup.php',
          method: "post",
          data: { 'creationcompte':1 },
          dataType: 'json'})
    .done(function(data) { 
      if ( data['val'] )
        $('#fenetre').append('<p class="oubli"><a href="gestioncompte?creation">Demander une création de compte</a></p>');
    });
    $('#fenetre input').entreevalide($('#fenetre form')).first().focus();
    // Envoi
    $('#fenetre a.icon-ok').on("click",function () {
      $.ajax({url: 'ajax.php',
              method: "post",
              data: $('#fenetre form').serialize()+'&connexion=1', 
              dataType: 'json' 
            });
    });
  });

  // Déconnexion
  $('a.icon-deconnexion').on("click",function(e) {
    $.ajax({url: 'ajax.php',
            method: "post",
            data: { action:'deconnexion' },
            dataType: 'json'
          });
  });

  // Menu mobile
  $('.icon-menu').on("click", function(e) {
    $('#menu').toggleClass('visible');
    if ( $('#menu').hasClass('visible') )  {
      $('<div id="menu_fond"></div>').appendTo('body').on("click", function() {
        $(this).remove();
        $('#menu').removeClass('visible');
      });
    }
    else 
      $('#menu_fond').remove();
  });

  // Changement de Cahier si interface globale et si compte global existant contenant au moins un autre Cahier
  $('a.icon-echange').on("click",function() {
    $.ajax({url: 'recup.php',
            method: "post",
            data: { action:'compteglobal' },
            dataType: 'json',
            afficheform: function(data) {
              popup('<h3>Changer de Cahier</h3><div></div><p>Cette liste est éditable sur l\'<a href="../connexion/">interface de connexion globale</a>.</p>',true);
              var f = $('#fenetre');
              // Récupération des valeurs et écriture 
              var cahiers = data['cahiers'];
              for ( var rep in cahiers )
                $('div',f).attr('id','cahiers').append('<a href="/'+rep+'/">'+cahiers[rep]+'</a>');
            }
    });
  });

  // Affichage des informations sur le flux RSS
  $('a.icon-rss').on("click", function() { popup($('#aide-rss').html(),false); });

  // Agenda : clic sur un événement dans le tableau clignote dans la liste
  $('.evnmt').on("click", function() {
    el = $('article[data-id="'+$(this).parent().data('id')+'"]').removeClass('flash');
    el.deplace_viewport().addClass('flash');
  });

  // Pour la fonction bloque() de blocage de la page en cas de saisie non terminée
  window.addEventListener('beforeunload', function (e) {
    if ( $('.nepassortir').length )  {
      e.preventDefault();
      e.returnValue = '';
      $('.nepassortir').addClass('nok');
    }
  });

  // Changer le thème si demandé
  $('footer .icon-theme').on("click", function () {
    changeTheme((localStorage.getItem('theme') || themeOS) == 'light' ? 'dark' : 'light');
  });

});
