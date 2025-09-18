/*//////////////////////////////////////////////////////////////////////////////
Éléments JavaScript pour l'administration de Cahier de Prépa

* Les fonctions communes aux visualisations avec ou sans droit d'édition sont
disponibles dans commun.js : ajaxSend, affiche, popup, reconnect
* Les éléments de classe "editable" peuvent être transformés en mode édition par
la fonction transforme, automatiquement exécutée. L'attribut data-id est alors
nécessaire, et doit être de la forme table|champ|id (table=action).
* Les éléments de classe "edithtml" sont considérés comme contenant des
informations présentées en html : la fonction textareahtml crée alors un élément
de type textarea, un élément de type div éditable (contenteditable=true), et
ajoute des boutons facilitant les modifications. La textarea et la div éditable
sont alternativement visibles, à la demande.
* Les liens de classe icon-aide lancent automatiquement une aide par la fonction
popup. Le contenu est récupéré dans les div de classe aide-xxx où xxx est la
valeur de l'attribut data-id du lien (ces div sont automatiquement non affichées
en css).
//////////////////////////////////////////////////////////////////////////////*/


///////////////
// Affichage //
///////////////

// Demande de confirmation, pour une suppression par exemple
function confirmation(question, element, action) {
  popup('<h3>Demande de confirmation</h3><p>'+question+'</p><p class="confirmation"><button class="icon-ok"></button>&nbsp;&nbsp;&nbsp;<button class="icon-annule"></button></p>',true);
  $('#fenetre .icon-ok').on("click",function() {
    action(element);
    $('#fenetre,#fenetre_fond').remove();
  });
  $('#fenetre .icon-annule').on("click",function() {
    $('#fenetre,#fenetre_fond').remove();
  });
}

// Pliage/dépliage vertical des lignes dans un tableau
function plie() {
  var lignes = $(this).parent().parent().nextUntil('.categorie');
  if ( $(this).hasClass('icon-deplie') )  {
    lignes.children().wrapInner('<div></div>').addClass('cache');
    lignes.find('div').slideUp(1000); 
    window.setTimeout(function() { 
      lignes.hide(0).find('div').children().unwrap();
      lignes.find('div').parent().html(function(){ return $(this).children().html(); });
    },1000);
  }
  else  {
    lignes.show(0);
    lignes.children().wrapInner('<div style="display:none;"></div>');
    lignes.find('div').slideDown(1000);
    window.setTimeout(function() { 
      lignes.find('div').children().unwrap();
      lignes.find('div').parent().html(function(){ return $(this).children().html(); });
      lignes.children().removeClass('cache'); 
    },1000);
  }
  $(this).toggleClass('icon-plie icon-deplie');
}

// Affichage des accès/possibilités d'édition
function affiche_titleplus() { 
  popup('<br><p>' + ( this.dataset.title || $('#aide-'+this.id).html() ) + '</p><br>',true);
  $("#fenetre").css('top',window.scrollY+this.getBoundingClientRect().bottom+5).css('position','absolute');
  var textematiere = $('body').data('matiere') ? ' associés à la matière' : '';
  if ( $("body").data('action') == 'infos' )
    var element = 'Cette information'
  else if ( $("body").data('action') == 'agenda' )
    var element = 'Cet événement'
  else 
    var element = ( $(this).parent().hasClass('doc') ) ? 'Ce document' : 'Ce répertoire';
  // Icône de protection d'élément
  if ( $(this).hasClass('icon-lock') && !$(this).parent().is('h1') ) {
    $("#fenetre").append('<h4>Comparaison des accès :</h4>\
<table class="centre">\
  <tr><th></th><th>Invités</th><th>Élèves</th><th>Colleurs</th><th>Lycée</th><th>Profs</th></tr>\
  <tr id="ligne1"><td>Cette page</td><td></td><td></td><td></td><td></td><td></td></tr>\
  <tr id="ligne2"><td>'+element+'</td><td></td><td></td><td></td><td></td><td></td></tr>\
</table>');
    var p1 = $('body').data('protection')-1;
    var p2 = $(this).parent().data('protection')-1;
    for (var a=0; a < 5; a++) {
      if ( !( p1>>a & 1 ) )  $('#ligne1 td').eq(a+1).text('X');
      if ( !( p2>>a & 1 ) )  $('#ligne2 td').eq(a+1).text('X');
    }
    if ( p1 < 0 )  $('#ligne1').html('<td>Cette page</td><td colspan="5">Visible sans connexion</td>');
    if ( p1 == 31 )  $('#ligne1').html('<td>Cette page</td><td colspan="5">Visible uniquement par les profs'+textematiere+'</td>');
    if ( p2 < 0 )  $('#ligne2').html('<td>'+element+'</td><td colspan="5">Visible sans connexion</td>');
  }
  // Icône d'édition d'élément (informations seulement)
  if ( $(this).hasClass('icon-edite') && ( typeof $(this).parent().data('edition') !== 'undefined' ) ) {
    if ( $('body').data('matiere') )
      $("#fenetre").append('<h4>Comparaison des possibilités d\'édition :</h4>\
<table class="centre">\
  <tr><th></th><th>Élèves</th><th>Colleurs</th><th>Lycée</th><th>Profs hors matière</th></tr>\
  <tr id="ligne1"><td>Cette page</td><td></td><td></td><td></td><td></td></tr>\
  <tr id="ligne2"><td>'+element+'</td><td></td><td></td><td></td><td></td></tr>\
</table>');
    else
      $("#fenetre").append('<h4>Comparaison des possibilités d\'édition :</h4>\
<table class="centre">\
  <tr><th></th><th>Élèves</th><th>Colleurs</th><th>Lycée</th><th>Profs</th></tr>\
  <tr id="ligne1"><td>Cette page</td><td></td><td></td><td></td><td>X</td></tr>\
  <tr id="ligne2"><td>'+element+'</td><td></td><td></td><td></td><td>X</td></tr>\
</table>');
    var e1 = $('body').data('edition')-1;
    var e2 = $(this).parent().data('edition')-1;
    for (var a=1; a < 5; a++) {
      if ( e1>>a & 1 )  $('#ligne1 td').eq(a).text('X');
      if ( e2>>a & 1 )  $('#ligne2 td').eq(a).text('X');
    }
    if ( e1 < 0 )  $('#ligne1').html('<td>Cette page</td><td colspan="4">Éditable uniquement par les profs'+textematiere+'</td>');
    if ( e2 < 0 )  $('#ligne2').html('<td>'+element+'</td><td colspan="4">Éditable uniquement par les profs'+textematiere+'</td>');
  }
  // Arrêt du bubbling si un affichable dans un lien
  return false;
}

// Réglage du mode de lecture
$.fn.reglagelecture = function() {
  var val = $('body').data('modelecture');
  var comptes = ['utilisateur non connecté','compte invité','élève','colleur','compte de type lycée','professeur (non associé à la matière pour les pages spécifiques à une matière)'];
  // Affichage du formulaire de sélection
  this.on("click",function() {
    popup('<h3>Réglage du mode de lecture</h3>',true);
    var form = $('<form><p>Vous pouvez ici régler le «&nbsp;mode de lecture&nbsp;»&nbsp;: cela vous permet de voir les pages contenant l\'icône&nbsp;<span class="icon-lecture"></span> comme si vous aviez un compte d\'un autre type que le vôtre.</p></form>').appendTo($('#fenetre'));
    form.append( val ? '<p class="annonce">Vous visualisez actuellement cette page comme le fait un '+comptes[val-1]+'.</p><input type="button" class="ligne" name="mode" value="Revenir à la vue initiale de votre compte" data-id="0">'
                     : '<p class="annonce">Vous visualisez actuellement cette page normalement.</p>');
    comptes.forEach(function(e,i) {
      form.append('<input type="button" class="ligne" name="mode" value="'+e[0].toUpperCase()+e.substring(1)+'" data-id="'+(i+1)+'">');
    });
    form.append('<br><h3>Imprimer cette page</h3><p>Vous pouvez ici imprimer cette page, en changeant le titre si besoin dans la case ci-dessous. Quel que soit le mode de lecture choisi, le menu et les icônes d\'action disparaîtront automatiquement.</p><p class="ligne-avec-bouton"><input id="titrepage" type="text" size=50 placeholder="Titre de la page"><button class="icon-imprime"></button></p>');
    // Impression
    $('#titrepage',form).val($('header h1').text()).on('keypress',function(e) { if ( e.which == 13 ) { $('button',form).click(); return false; } });
    $('button',form).on("click",function() {
      var h1 = $('header h1').clone();
      $('header h1').text($('#titrepage',form).val());
      $('#fenetre,#fenetre_fond').remove();
      window.print();
      $('header h1').replaceWith(h1);
      return false;
    });
    // Envoi
    $('input[type="button"]',form).on("click",function () {
      $.ajax({url: 'ajax.php',
              method: "post",
              data: { action:'ml', mode:this.dataset['id'] }, 
              dataType: 'json'
            });
    }).filter('[data-id='+val+']').prop('disabled',true);
  });
  
  // Choix de l'élève pour notescolles et transferts
  $('#selecteleve.topbarre select').on("change",function() {
    $('body').load($('body').data('action')+window.location.search,{ eid: this.value });
  });
}

// Recherche insensible à la casse
$.expr[':'].icontains = function(e, i, m) {
  return (e.textContent || e.innerText || '').toLowerCase().indexOf((m[3] || '').toLowerCase()) >= 0;
};

//////////////////////////////////////////////////////////////
// Édition de textes : facilités de modication des éléments //
//////////////////////////////////////////////////////////////

// Transformation d'une textarea pour l'édition de code HTML
// Ajoute des boutons d'édition et la possibilité de commuter l'affichage avec
// une div éditable. Fonction à appliquer sur les textarea de classe edithtml
$.fn.textareahtml = function() {
  this.each(function() {
    var ta = $(this);
    var placeholder = this.getAttribute('placeholder');
    // Modification du placeholder
    this.setAttribute('placeholder',placeholder+'. Formattage en HTML, balises visibles.');
    // Ajout d'éléments : boutons, div éditable
    var ce = $('<div contenteditable="true" placeholder="'+placeholder+'"></div>').insertAfter(ta.before(boutons)).hide(0);
    var boutonretour = ta.prev().children(".icon-retour");
    // Classe 'ligne' aux boutons et à la div éditable si c'est le cas du textarea
    if ( ta.hasClass('ligne') ) {
      ce.addClass('ligne');
      ta.prev().addClass('ligne');
    }
    // Retour à la ligne par Entrée, nettoyage au copié-collé
    ta.on("keypress",function(e) {
        if (e.which == 13)
          this.value = nettoie(this.value);
      })
      .on("paste cut",function() {
        var el = this;
        setTimeout(function() {
          el.value = nettoie(el.value);
        }, 100);
      });
    ce.on("keypress",function(e) {
        if (e.which == 13)
          boutonretour.click();
      })
      .on("paste cut",function() {
        var el = this;
        setTimeout(function() {
          el.innerHTML = nettoie(el.innerHTML)+'<br>';
        }, 100);
      });
    // Clic bouton "nosource" : passage de textarea à div
    ta.prev().children('.icon-nosource').on("click",function(e) {
      e.preventDefault();
      // Modification des visibilités
      ta.hide(0);
      ce.show(0).css("min-height",ta.outerHeight());
      $(this).hide(0).prev().show(0);
      // Nettoyage et synchronisation (change -> mise à jour du placeholder)
      ce.focus().html(nettoie(ta.val())).change();
      // Mise en place du curseur à la fin
      if ( window.getSelection ) {
        var r = document.createRange();
        r.selectNodeContents(ce[0]);
        r.collapse(false);
        var s = window.getSelection();
        s.removeAllRanges();
        s.addRange(r);
      }
      else {
        var r = document.body.createTextRange();
        r.moveToElementText(ce[0]);
        r.collapse(false);
        r.select();
      }
    });
    // Clic bouton "source" : passage de div à textarea
    ta.prev().children('.icon-source').on("click",function(e) {
      e.preventDefault();
      // Modification des visibilités
      ce.hide(0);
      ta.show(0).css("height",ce.height());
      $(this).hide(0).next().show(0);
      // Nettoyage et synchronisation (change -> mise à jour du placeholder)
      ta.focus().val(nettoie(ce.html()));
    }).hide(0);
    // Clic bouton aide
    ta.prev().children('.icon-aide').on("click",function(e) {
      e.preventDefault();
      aidetexte();
    });
    // Autres clics
    ta.prev().children().not('.icon-nosource,.icon-source,.icon-aide').on("click",function(e) {
      e.preventDefault();
      window['insertion_'+this.className.substring(5)]($(this));
    });
  });
}

// Édition "en place"
// Fonction à appliquer sur les éléments de classe editable
$.fn.editinplace = function() {
  this.each(function() {
    $(this).data('original', $(this).is('div') ? this.innerHTML : this.textContent );
  }).append($('<a class="icon-edite" title="Modifier"></a>').on("click",transforme));
}

// Transformation d'un élément h3/div de classe editable en input/textarea
function transforme() {
  var el = $(this).parent().addClass('avecform');
  // Création d'une textarea si div
  if ( el.is('div') ) {
    var input = $('<textarea name="val" rows="'+(el.data('original').split(/\r\n|\r|\n/).length+3)+'"></textarea>').val(nettoie(el.data('original')));
    el.empty().append($('<form></form>').append(input));
    // Cas des informations et colles : case à cocher pour maj de la date de publi
    if ( el.hasClass('majpubli') )
      input.after('<p class="ligne"><label for="publi">Publier en tant que mise à jour&nbsp;: </label><input type="checkbox" id="publi" name="publi" value="1" checked></p>');
    // Boutons si edithtml
    if ( el.hasClass('edithtml') )
      input.textareahtml();
  }
  // Création d'un input si non div
  else {
    var input = $('<input type="text" name="val">').val(el.data('original'));
    el.empty().append($('<form class="edition"></form>').append(input).on("submit",function() {
      $(this).children('a.icon-ok').click();
      return false; 
    }) );
    if ( el.hasClass('duree') )
      input.datetimepicker({ format: 'Ghi', datepicker: false, defaultTime: '0h00', step: 10 })
           .on("change",function() { $(this).removeClass('auto'); });
  }
  input.attr('placeholder',el.attr('placeholder')).focus().bloque();
  // Envoi
  $('<a class="icon-ok" title="Valider"></a>').appendTo(el.children()).on("click",function() {
    // Nettoyage et synchronisation si besoin
    if ( el.hasClass('edithtml') )
      input.val(nettoie( ( input.is(':visible') ) ? input.val() : input.next().html() ));
    // Envoi
    $.ajax({url: 'ajax.php',
            method: "post",
            data: { action:el.parent().data('action') || $('body').data('action'), champ:el.data('champ'), id:el.parent().data('id') || el.data('id'), val:input.val(), publi:el.find(':checkbox').is(':checked') || undefined, matiere:$('body').data('matiere') },
            dataType: 'json',
            el: el,
            fonction: function(el) {
              el.removeClass('avecform').data('original',input.val()).html(input.val()).editinplace();
            }
    });
  });
  // Annulation
  $('<a class="icon-annule" title="Annuler"></a>').appendTo(el.children()).on("click",function() {
    el.removeClass('avecform').html(el.data('original')).editinplace();
    if ( typeof MathJax != 'undefined' )
      MathJax.Hub.Queue(["Typeset",MathJax.Hub]);
  });
}

// Édition "en place" des propriétés des éléments des cahiers de texte
// Fonction à appliquer sur les éléments de classe titrecdt
$.fn.editinplacecdt = function() {
  this.each(function() {
    $(this).wrapInner('<span></span>').data('original',this.textContent);
  }).append($('<a class="icon-edite" title="Modifier"></a>').on("click",transformecdt));
}

// Transformation d'un élément de classe titrecdt pour son édition
function transformecdt() {
  var el = $(this).parent();
  // Création du formulaire
  $('.icon-edite',el).remove();
  var form = $('<form class="titrecdt"></form>').insertBefore(el.parent().children('div')).html($('#form-cdt').html()).on("submit",function() {
    $(this).prev().children('a.icon-ok').click();
    return false; 
  });
  // Création de l'identifiant des champs à partir du name
  $('input, select',form).attr('id',function(){ return this.getAttribute('name'); });
  // Récupération des valeurs et modification initiale du formulaire
  var valeurs = el.data('donnees');
  for ( var cle in valeurs )
    $('#'+cle,form).val(valeurs[cle]);
  // Mise en place des facilités  
  form.init_cdt_boutons();
  // Mise à jour du titre si modification
  $('input,#demigroupe',form).on('change keyup',function() {
    var t = new Date($('#jour',form).val().replace(/(.{2})\/(.{2})\/(.{4})/,function(tout,x,y,z) {return z+'-'+y+'-'+x; }));
    var dg = ( $('#demigroupe',form).val() == 1 ) ? ' (en demi-groupe)' : '';
    switch ( parseInt(seances[$('#tid',form).val()]) ) {
      case 0:
        var titre = jours[t.getDay()]+' '+$('#jour',form).val()+' à '+$('#h_debut',form).val()+' : '+$('#tid option:selected',form).text()+dg;
        break;
      case 1:
        var titre = jours[t.getDay()]+' '+$('#jour',form).val()+' de '+$('#h_debut',form).val()+' à '+$('#h_fin',form).val()+' : '+$('#tid option:selected',form).text()+dg;
        break;
      case 2:
        var titre = jours[t.getDay()]+' '+$('#jour',form).val()+' : '+$('#tid option:selected',form).text()+' pour le '+$('#pour',form).val()+dg;
        break;
      case 3:
        var titre = jours[t.getDay()]+' '+$('#jour',form).val()+' : '+$('#tid option:selected',form).text()+dg;
        break;
      case 4:
        var titre = jours[t.getDay()]+' '+$('#jour',form).val();
        break;
      case 5:
        var titre = '[Entrée hebdomadaire]';
    }
    $('span',el).text(titre);
  }).bloque().entreevalide(form);
  // Envoi
  $('<a class="icon-ok" title="Valider"></a>').appendTo(el).on("click",function() {
    // Envoi
    $.ajax({url: 'ajax.php',
            method: "post",
            data: 'action=cdt&id='+el.parent().data('id')+'&'+form.serialize(),
            dataType: 'json',
            el: el,
            fonction: function(el) {
              el.data('original',$('span',el).text()).data('donnees',{tid:$('#tid',form).val(),jour:$('#jour',form).val(),h_debut:$('#h_debut',form).val(),h_fin:$('#h_fin',form).val(),pour:$('#pour',form).val(),demigroupe:$('#demigroupe',form).val()}).text(el.data('original')).editinplacecdt();
              form.remove();
            }
    });
  });
  // Annulation
  $('<a class="icon-annule" title="Annuler"></a>').appendTo(el).on("click",function() {
    form.remove();
    el.text(el.data('original')).editinplacecdt();
  });
}

// Édition "en place" des propriétés des événements de l'agenda
// Fonction à appliquer sur les éléments de classe titreagenda
$.fn.editinplaceagenda = function() {
  //this.each(function() {
  //  $(this).wrapInner('<span></span>').data('original',this.textContent);
  //})
  this.append($('<a class="icon-edite" title="Modifier"></a>').on("click",transformeagenda));
}

// Transformation d'un élément de classe titrecdt pour son édition
function transformeagenda() {
  var el = $(this).parent();
  // Création du formulaire
  $('.icon-edite',el).remove();
  var form = $('<form class="titreagenda"></form>').insertBefore(el.parent().children('div')).html($('#form-agenda').html()).on("submit",function() {
    $(this).prev().children('a.icon-ok').click();
    return false; 
  });
  // Création de l'identifiant des champs à partir du name
  $('input, select',form).attr('id',function(){ return this.getAttribute('name'); });
  // Récupération des valeurs et modification initiale du formulaire
  var valeurs = el.data('donnees');
  for ( var cle in valeurs )
    $('#'+cle,form).val(valeurs[cle]);
  // Gestion des dates
  $('#debut').datetimepicker({
    onShow: function()  {
      this.setOptions({maxDate: $('#fin').val() || false });
    },
    onClose: function(t,input) {
      $('#fin').val(function(i,v){ return v || input.val(); });
    }
  });
  $('#fin').datetimepicker({
    onShow: function()  {
      this.setOptions({minDate: $('#debut').val() || false });
    },
    onClose: function(t,input) {
      $('#debut').val(function(i,v){ return v || input.val(); });
    }
  });
  $('#jours').on('change',function() {
    var v;
    if ( this.checked )  {
      $('#debut,#fin').each(function() {
        v = this.value.split(' ');
        $(this).val(v[0]).attr('data-heure',v[1]).datetimepicker({ format: 'd/m/Y', timepicker: false });
      });
    }
    else  {
      $('#debut,#fin').each(function() {
        if ( this.hasAttribute('data-heure') )
          $(this).val(this.value+' '+$(this).attr('data-heure')).removeAttr('data-heure');
        $(this).datetimepicker({ format: 'd/m/Y Ghi', timepicker: true });
      });
    }
  }).prop("checked",valeurs['jours']).change();
  $('input, select',form).bloque().entreevalide(form);
  // Envoi
  $('<a class="icon-ok" title="Valider"></a>').appendTo(el).on("click",function() {
    // Envoi
    $.ajax({url: 'ajax.php',
            method: "post",
            data: 'action=agenda&id='+el.parent().data('id')+'&'+form.serialize(),
            dataType: 'json',
            el: el,
            fonction: function(el) {
              form.remove();
              $('.icon-annule,.icon-ok',el).remove();
              el.append($('<a class="icon-edite" title="Modifier"></a>').on("click",transformeagenda));
            }
    });
  });
  // Annulation
  $('<a class="icon-annule" title="Annuler"></a>').appendTo(el).on("click",function() {
    form.remove();
    $('.icon-annule,.icon-ok',el).remove();
    el.append($('<a class="icon-edite" title="Modifier"></a>').on("click",transformeagenda));
  });
}

// Nettoyage des contenus de textarea pour l'édition de code HTML
function nettoie(html) {
  // Suppression du span cdptmp ajouté par la fonction insert()
  if ( html.indexOf('cdptmp') > 0 ) {
    // Suppression des spans non vides
    var tmp = $('<div>'+html+'</div>');
    tmp.find('.cdptmp').contents().unwrap();
    html = tmp.html();
    // Suppression des spans restants, vides
    if ( html.indexOf('cdptmp') > 0 )
      html = html.replace(/<span class="cdptmp"><\/span>/g,'');
  }
  // Autres modifications
  return html.replace(/(<\/?[A-Z]+)([^>]*>)/g, function(tout,x,y) { return x.toLowerCase()+y; })  // Minuscules pour les balises
             .replace(/[\r\n ]+/g,' ')  // Suppression des retours à la ligne et espaces multiples
             .replace(/(<br>)+[ ]?<\/(p|div|li|h)/g, function(tout,x,y) { return '</'+y; }).replace(/<br>/g, '<br>\n')  // Suppression des <br> multiples
             .replace(/<(p|div|li|h)/g, function(x) { return '\n'+x; })  // Retour à la ligne avant <p>, <div>, <li>, <h*>
             .replace(/<\/(p|div|li|h.)>/g, function(x) { return x+'\n'; }) // Retour à la ligne après </p>, </div>, </li>, </h*>
             .replace(/<\/?(ul|ol)[^>]*>/g, function(x) { return '\n'+x+'\n'; })  // Retour à la ligne avant et après <ul>, </ul>, <ol>, </ol>
             // Formattage en paragraphe d'une ligne finie par <br> et non commencée par <p>, <div>, <ul>, <ol>, <li>, <h*>
             .replace(/^(?!(<p|<div|<ul|<ol|<li|<h))(.+)<br>$/gm, function(tout,x,y) { return '<p>'+y+'</p>'; })
             // Formattage en paragraphe d'une ligne non commencée par <p>, <div>, <ul>, <ol>, <li>, <h*>, non fermée par </p>, </div>, </ul>, </ol>, </li>, </h*>
             .replace(/^(?!(<(p|div|ul|ol|li)))[ ]?(.+)[ ]?$/gm, function(t,x,y,z) { return ( z.match(/.*(p|div|ul|ol|li|h.)>$/) ) ? z : '<p>'+z+'</p>'; })
             // Suppression des lignes contenant une seule balise <p>, </p>, <div>, </div>, <h*>, </h*>, <br> (avec éventuellement des espaces autour)
             // Suppression des lignes de paragraphes/div/titres vides (contenant des espaces ou un <br>)
             .replace(/^[ ]?(<\/?(br|p|div|h.)>){0,2}[ ]?(<\/(p|div|h.)>)?[ ]?$/gm,'').replace(/^\n/gm,'')
             // Indentation devant <li>
             .replace(/<li/g,'  <li');
}

// Insertion lancée par les boutons.
// Fonction générique pour l'utilisation des boutons insérés pour les textarea
// d'édition en HTML. Arguments :
//  * el = identifiant jquery du bouton
//  * debut = ce qui sera inséré avant la sélection
//  * fin = ce qui sera inséré après la sélection
//  * milieu = ce qui sera inséré à la place de la sélection (si renseigné)
// Après insertion, la sélection est conservée (ou placée sur milieu)
function insert(el,debut,fin,milieu) {
  // Récupération et modification du contenu
  var contenant = el.parent().siblings('textarea,[contenteditable]').filter(':visible')[0];
  if ( !contenant.hasAttribute('data-selection') )
    marqueselection(el);
  var texte = ( milieu === undefined ) ? debut+'Í'+contenant.getAttribute('data-selection')+'Ì'+fin : debut+'Í'+milieu+'Ì'+fin;
  var contenu = nettoie(contenant.getAttribute('data-contenu').replace(/Í.*Ì/,texte));
  // Affichage
  if ( contenant.tagName == 'TEXTAREA' )
    contenant.value = contenu.replace(/[ÍÌ]/g,'');
  else
    contenant.innerHTML = contenu.replace(/[ÍÌ]/g,'');
  // Suppression des attributs liés à la sélection
  marqueselection(el,true);
  // Resélection
  // Cas des textarea, navigateurs modernes
  if ( ( contenant.tagName == 'TEXTAREA' ) && ( contenant.selectionStart !== undefined ) ) {
    contenant.selectionStart = contenu.indexOf('Í');
    contenant.selectionEnd = contenu.indexOf('Ì')-1;
    contenant.focus();
  }
  // Cas des textarea et divs éditables, navigateurs anciens (IE<9)
  else if ( document.selection ) {
    // On doit compter les caractères de texte uniquement : suppression des
    // balises si contenant est div editable
    if ( contenant.tagName != 'TEXTAREA' )
      contenu = contenu.replace(/(<([^>]+)>)[\n]*/g,'');
    range = document.body.createTextRange();
    range.moveToElementText(contenant);
    range.collapse(true); 
    range.moveEnd("character", contenu.indexOf('Ì')-1);
    range.moveStart("character", contenu.indexOf('Í'));
    range.select();
  }
  // Cas des divs éditables, navigateurs modernes
  else if (window.getSelection) {
    contenant.innerHTML = contenu.replace('Í','<span class="cdptmp">').replace('Ì','</span>')+'<br>';
    selection = window.getSelection();
    range = document.createRange();
    range.selectNodeContents($(contenant).find('.cdptmp')[0]);
    selection.removeAllRanges();
    selection.addRange(range);
    contenant.focus();
  }
}

// Marquage de la sélection
// Crée deux attributs sur la textarea/div éditable visible :
//  * data-selection = la sélection en cours
//  * data-contenu = le contenu entier de la textarea/div éditable, où la
// sélection est encadrée par Í (début) et Ì (fin)
// Retourne le texte effectivement sélectionné. Arguments : 
//  * el = identifiant jquery du bouton "appelant" (via la fonction insert)
//  * efface = si true, on efface simplement les attributs
function marqueselection(el,efface) {
  var contenant = el.parent().siblings('textarea,[contenteditable]').filter(':visible')[0];
  if ( efface ) {
    contenant.removeAttribute('data-selection');
    contenant.removeAttribute('data-contenu');
    return true;
  }
  var original = ( contenant.tagName == 'TEXTAREA' ) ? contenant.value : contenant.innerHTML;
  var sel = '';
  // Cas des textarea, navigateurs modernes
  if ( ( contenant.tagName == 'TEXTAREA' ) && ( contenant.selectionStart !== undefined ) ) {
    contenant.focus();
    sel = contenant.value.substring(contenant.selectionStart,contenant.selectionEnd);
    contenant.value = contenant.value.substr(0,contenant.selectionStart)+'Í'+sel+'Ì'+contenant.value.substring(contenant.selectionEnd);
  }
  // Cas des divs éditables, navigateurs modernes
  else if ( window.getSelection ) {
    var range = window.getSelection().getRangeAt(0);
    if ( ( contenant == range.commonAncestorContainer ) || $.contains(contenant,range.commonAncestorContainer) ) {
      var sel = window.getSelection().toString();
      range.deleteContents();
      range.insertNode(document.createTextNode('Í'+sel+'Ì'));
    }
  }
  // Cas des navigateurs anciens (IE<9)
  else {
    var range = document.selection.createRange();
    if ( ( contenant == range.parentElement() ) || $.contains(contenant,range.parentElement()) ) {
      var sel = document.selection.createRange().text;
      document.selection.createRange().text = 'Í'+sel+'Ì';
    }
  }
  // Remise à l'orgine
  if ( contenant.tagName == 'TEXTAREA' ) {
    var contenu = contenant.value;
    contenant.value = original;
  }
  else {
    var contenu = contenant.innerHTML;
    $(contenant).html(original); // Bug IE8 : modification de innerHTML impossible
  }
  // Par défaut : sélection à la fin
  if ( contenu.indexOf('Ì') < 0 )
    contenu = contenu + 'ÍÌ';
  // Enregistrement et retour
  contenant.setAttribute('data-selection',sel);
  contenant.setAttribute('data-contenu',contenu);
  return sel;
}

// Suppression du code MathJax 
// Supprime le code ajouté par MathJax et le retransforme en Tex originel
$.fn.supprimeMathJax = function() {
  if ( !$(this).find('script').length ) return $(this)
  var copie = $(this).clone();
  $('script[type="math/tex"]',copie).each(function() { $(this).parent().text( '$'+$(this).text()+'$' ); });
  $('script[type="math/tex; mode=display"]',copie).each(function() { $(this).parent().text( '\\['+$(this).text()+'\\]' ); });
  return copie
}

////////////////////////////////////////////////////////////
// Édition de textes : boutons pour les textarea.edithtml //
////////////////////////////////////////////////////////////

// Définition des boutons
var boutons = '\
<p class="boutons">\
  <button class="icon-titres" title="Niveaux de titres"></button>\
  <button class="icon-par1" title="Paragraphe"></button>\
  <button class="icon-par2" title="Paragraphe important"></button>\
  <button class="icon-par3" title="Paragraphe très important"></button>\
  <button class="icon-retour" title="Retour à la ligne"></button>\
  <button class="icon-gras" title="Gras"></button>\
  <button class="icon-italique" title="Italique"></button>\
  <button class="icon-souligne" title="Souligné"></button>\
  <button class="icon-omega" title="Insérer une lettre grecque"></button>\
  <button class="icon-sigma" title="Insérer un signe mathématique"></button>\
  <button class="icon-exp" title="Exposant"></button>\
  <button class="icon-ind" title="Indice"></button>\
  <button class="icon-ol" title="Liste énumérée"></button>\
  <button class="icon-ul" title="Liste à puces"></button>\
  <button class="icon-lien1" title="Lien vers un document du site"></button>\
  <button class="icon-lien2" title="Lien internet"></button>\
  <button class="icon-tex" title="LATEX!"></button>\
  <button class="icon-source" title="Voir et éditer le code html"></button>\
  <button class="icon-nosource" title="Voir et éditer le texte formaté"></button>\
  <button class="icon-aide" title="Aide pour cet éditeur de texte"></button>\
</p>';

// Fonctions lancées par les boutons appelant une fenêtre par la fonction popup
function insertion_titres(el) {
  popup('<a class="icon-ok" title="Valider"></a><h3>Insertion d\'un titre</h3>\
  <p>Choisissez le type du titre ci-dessous. Vous pouvez éventuellement modifier le texte (ou pourrez le faire ultérieurement). Il est conseillé d\'utiliser des titres de niveau 2 pour les titres dans les programmes de colle.</p>\
  <input type="radio" name="titre" id="t3" value="3" checked><h3><label for="t3">Titre de niveau 1 (pour les I,II...)</label></h3><br>\
  <input type="radio" name="titre" id="t4" value="4"><h4><label for="t4">Titre de niveau 2 (pour les 1,2...)</label></h4><br>\
  <input type="radio" name="titre" id="t5" value="5"><h5><label for="t5">Titre de niveau 3 (pour les a,b...)</label></h5><br>\
  <input type="radio" name="titre" id="t6" value="6"><h6><label for="t6">Titre de niveau 4</label></h6><br>\
  <p class="ligne"><label for="texte">Texte&nbsp;: </label><input type="text" id="texte" value="'+marqueselection(el)+'" size="80"></p>\
  <hr><h3>Aperçu</h3><div id="apercu"></div>',true);
  // Modification automatique de l'aperçu
  $('#fenetre input').on("click keyup",function() {
    var balise = 'h'+$("[name='titre']:checked").val();
    $('#apercu').html('<'+balise+'>'+( ( $('#texte').val().length ) ? $('#texte').val() : 'Texte du titre' )+'</'+balise+'>');
  }).first().keyup();
  // Insertion par appui sur Entrée
  $('#texte').on("keypress",function(e) {
    if ( e.which == 13 )
      $('#fenetre a.icon-ok').click();
  }).focus();
  // Insertion
  $('#fenetre a.icon-ok').on("click",function() {
    var balise = 'h'+$("[name='titre']:checked").val();
    insert(el,'<'+balise+'>','</'+balise+'>',$('#texte').val());
    $('#fenetre,#fenetre_fond').remove();
  });
  // Suppression des attributs liés à la sélection lors de la fermeture
  $('#fenetre a.icon-ferme,#fenetre_fond').on("click",function() {
    marqueselection(el,true);
  });
}

function insertion_omega(el) {
  popup('<h3>Insertion d\'une lettre grecque</h3>\
  <p>Cliquez sur la lettre à insérer&nbsp;:</p>\
  <button>&alpha;</button> <button>&beta;</button> <button>&gamma;</button> <button>&Delta;</button> <button>&delta;</button> <button>&epsilon;</button> <button>&eta;</button> <button>&Theta;</button> <button>&theta;</button> <button>&Lambda;</button> <button>&lambda;</button> <button>&mu;</button> <button>&nu;</button> <button>&xi;</button> <button>&Pi;</button> <button>&pi;</button> <button>&rho;</button> <button>&Sigma;</button> <button>&sigma;</button> <button>&tau;</button> <button>&upsilon;</button> <button>&Phi;</button> <button>&phi;</button> <button>&Psi;</button> <button>&psi;</button> <button>&Omega;</button> <button>&omega;</button>',true);
  $('#fenetre button').on("click",function() {
    insert(el,'','',$(this).text());
    $('#fenetre,#fenetre_fond').remove();
  });
}

function insertion_sigma(el) {
  popup('<h3>Insertion d\'un symbole mathématique</h3>\
  <p>Cliquez sur le symbole à insérer&nbsp;:</p>\
  <button>&forall;</button> <button>&exist;</button> <button>&part;</button> <button>&nabla;</button> <button>&prod;</button> <button>&sum;</button> <button>&plusmn;</button> <button>&radic;</button> <button>&infin;</button> <button>&int;</button> <button>&prop;</button> <button>&sim;</button> <button>&cong;</button> <button>&asymp;</button> <button>&ne;</button> <button>&equiv;</button> <button>&le;</button> <button>&ge;</button> <button>&sub;</button> <button>&sup;</button> <button>&nsub;</button> <button>&sube;</button> <button>&supe;</button> <button>&isin;</button> <button>&notin;</button> <button>&ni;</button> <button>&oplus;</button> <button>&otimes;</button> <button>&sdot;</button> <button>&and;</button> <button>&or;</button> <button>&cap;</button> <button>&cup;</button> <button>&real;</button> <button>&image;</button> <button>&empty;</button> <button>&deg;</button> <button>&prime;</button> <button>&micro;</button> <button>&larr;</button> <button>&uarr;</button> <button>&rarr;</button> <button>&darr;</button> <button>&harr;</button> <button>&lArr;</button> <button>&uArr;</button> <button>&rArr;</button> <button>&dArr;</button> <button>&hArr;</button>',true);
  $('#fenetre button').on("click",function() {
    insert(el,'','',$(this).text());
    $('#fenetre,#fenetre_fond').remove();
  });
}

function insertion_ol(el) {
  popup('<a class="icon-ok" title="Valider"></a><h3>Insertion d\'une liste numérotée</h3>\
  <p>Choisissez le type de numérotation et la valeur de départ de la liste ci-dessous. Vous pouvez éventuellement modifier les différents éléments en les écrivant ligne par ligne. Vous pourrez ajouter un élément ultérieurement en l\'encadrant par les balises &lt;li&gt; et &lt;/li&gt;.</p>\
  <p class="ligne"><label for="t1">Numérotation numérique (1, 2, 3...)</label><input type="radio" name="type" id="t1" value="1" checked></p>\
  <p class="ligne"><label for="t2">Numérotation alphabétique majuscule (A, B, C...)</label><input type="radio" name="type" id="t2" value="A"></p>\
  <p class="ligne"><label for="t3">Numérotation alphabétique minuscule (a, b, c...)</label><input type="radio" name="type" id="t3" value="a"></p>\
  <p class="ligne"><label for="t4">Numérotation romaine majuscule (I, II, III...)</label><input type="radio" name="type" id="t4" value="I"></p>\
  <p class="ligne"><label for="t5">Numérotation romaine minuscule (i, ii, iii...)</label><input type="radio" name="type" id="t5" value="i"></p>\
  <p class="ligne"><label for="debut">Valeur de début (numérique)</label><input type="text" id="debut" value="1"></p>\
  <p class="ligne"><label for="lignes">Textes (chaque ligne correspond à un élément de la liste)&nbsp;: </label></p>\
  <textarea id="lignes" rows="5">'+marqueselection(el)+'</textarea>\
  <hr><h3>Aperçu</h3><div id="apercu"></div>',true);
  // Modification automatique de l'aperçu
  $('#fenetre :input').on("click keyup",function() {
    var debut = $('#debut').val();
    debut = ( debut.length && ( debut > 1 ) ) ? ' start="'+debut+'"' : '';
    $('#apercu').html('<ol type="'+$("[name='type']:checked").val()+'"'+debut+'><li>'
                       +( ( $('#lignes').val().length ) ? $('#lignes').val().trim('\n').replace(/\n/g,'</li><li>') : 'Première ligne</li><li>Deuxième ligne</li><li>...' )
                       +'</li></ol>');
  }).first().keyup();
  $('#lignes').focus();
  // Insertion
  $('#fenetre a.icon-ok').on("click",function() {
    var debut = $('#debut').val();
    debut = ( debut.length && ( debut > 1 ) ) ? ' start="'+debut+'"' : '';
    var elements = $('#lignes').val().trim('\n');
    // On ne souhaite garder en sélection que la dernière ligne
    var index = elements.lastIndexOf('\n');
    if ( index > 0 ) {
      var dernier = elements.substring(index+1);
      elements = elements.substring(0,index);
    }
    else
      var dernier = '';
    // Insertion
    insert(el,'<ol type="'+$("[name='type']:checked").val()+'"'+debut+'><li>'+elements.replace(/\n/g,'</li><li>')+'</li><li>','</li></ol>',dernier);
    $('#fenetre,#fenetre_fond').remove();
  });
  // Suppression des attributs liés à la sélection lors de la fermeture
  $('#fenetre a.icon-ferme,#fenetre_fond').on("click",function() {
    marqueselection(el,true);
  });
}

function insertion_ul(el) {
  popup('<a class="icon-ok" title="Valider"></a><h3>Insertion d\'une liste à puces</h3>\
  <p>Vous pouvez éventuellement modifier les différents éléments en les écrivant ligne par ligne (chaque ligne correspond à un élément de la la liste). Vous pourrez ajouter un élément ultérieurement en l\'encadrant par les balises &lt;li&gt; et &lt;/li&gt;.</p>\
  <textarea id="lignes" rows="5">'+marqueselection(el)+'</textarea>\
  <hr><h3>Aperçu</h3><div id="apercu"></div>',true);
  // Modification automatique de l'aperçu
  $('#lignes').on("click keyup",function() {
    $('#apercu').html('<ul><li>'
                      +( ( $('#lignes').val().length ) ? $('#lignes').val().trim('\n').replace(/\n/g,'</li><li>') : 'Première ligne</li><li>Deuxième ligne</li><li>...' )
                      +'</li></ul>');
  }).keyup().focus();
  // Insertion
  $('#fenetre a.icon-ok').on("click",function() {
    var elements = $('#lignes').val().trim('\n');
    // On ne souhaite garder en sélection que la dernière ligne
    var index = elements.lastIndexOf('\n');
    if ( index > 0 ) { var dernier = elements.substring(index+1); elements = elements.substring(0,index); }
    else var dernier = '';
    // Insertion
    insert(el,'<ul><li>'+elements.replace(/\n/g,'</li><li>')+'</li><li>','</li></ul>',dernier);
    $('#fenetre,#fenetre_fond').remove();
  });
  // Suppression des attributs liés à la sélection lors de la fermeture
  $('#fenetre a.icon-ferme,#fenetre_fond').on("click",function() {
    marqueselection(el,true);
  });
}

function insertion_lien1(el) {
  var sel = marqueselection(el);
  // Préparation : fenêtre et fermeture
  popup('<a class="icon-ok" title="Valider"></a><h3>Insertion d\'un lien vers un document de Cahier de Prépa</h3>\
  <div><p style="text-align:center; margin: 2em 0;">[Récupération des listes de documents]</p></div>\
  <div style="display:none;"><hr><h3>Aperçu</h3><div id="apercu" style="text-align:center;">[Veuillez choisir un document]</div></div>',true);
  $('#fenetre a.icon-ferme,#fenetre_fond').on("click",function() {
    marqueselection(el,true);
  });
  // Récupération des listes de répertoires et de documents
  $.ajax({url: 'recup.php',
          method: "post",
          data: { action:'docs' },
          dataType: 'json'})
    .done(function(data) {
      // Fonction de mise à jour de l'aperçu
      var majapercu = function() {
        var apercu = $('#apercu');
        var id = $('#doc').val();
        var texte = $('#doc option:selected').text();
        // Rien à afficher : texte par défaut
        if ( id == 0 )
          apercu.html(texte);
        // Cas des pdfs affichés
        else if ( $('#vue').is(':checked') ) {
          var l = $('#largeur').val();
          if ( texte.slice(-4,-1) == 'pdf') {
            // Pas de pdf affiché précédemment
            if ( apercu.children('.pdf').length == 0 )
              apercu.html('<div><object data="download?id='+id+'" type="application/pdf" height="100%" width="100%"> <a href="download?id='+id+'">'+texte+'</a> </object></div>');
            // Changement de document
            else if ( apercu.find('object').attr('data').substr(12) != id )
              apercu.find('object').attr('data','download?id='+id).html('<a href="download?id='+id+'">'+texte+'</a>');
            // Changement de format
            apercu.children().attr('class','pdf '+$('#format').val());
            // Changement de largeur : seulement si largeur spécifiée
            if ( l ) {
              if ( l == 100 )
                apercu.children().removeAttr('style').children().attr('width','100%').removeAttr('style');
              else  {
                // Adaptation de la hauteur : doit être cohérent avec style.css
                switch ( $('#format').val() )  {
                  case 'portrait' :  var ratio = 1.38; break;
                  case 'paysage' :   var ratio = 0.74; break;
                  case 'hauteur50' : var ratio = 0.5; break;
                }
                apercu.children().css('padding-bottom',(ratio*l)+'%');
                apercu.find('object').attr('width',l+'%').css('left',(100-l)/2+'%');
              }
            }
          }
          // Cas des images affichées
          else if ( 'jpgpegpng'.indexOf(texte.slice(-4,-1)) > -1 ) {
            // Pas d'image affichée précédemment
            if ( apercu.children('img').length == 0 )
              apercu.css('text-align','').html('<img src="download?id='+id+'">');
            // Changement de document
            else if ( apercu.children().attr('src').substr(12) != id )
              apercu.children().attr('src','download?id='+id);
            // Changement de largeur : seulement si largeur spécifiée
            if ( l ) {
              if ( l == 100 )
                apercu.children().removeAttr('style');
              else
                apercu.children().css('width',l+'%').css('margin-left',(100-l)/2+'%');
            }
          }
        }
        // Cas des liens classiques
        else
          $('#apercu').css('text-align','center').html('<a onclick="return false;" href="download?id='+this.value+'">'+$('#texte').val()+'</a>');
      }

      // Fonction d'affichage
      var affichedocs = function(data) {
        $('#fenetre > div:first').html('\
  <p>Choisissez ci-dessous le répertoire puis le document à insérer. Vous pouvez aussi modifier le texte visible. Cela reste modifiable ultérieurement&nbsp;: le texte est situé entre les deux balises &lt;a...&gt; et &lt;/a&gt;.</p>\
  <p class="ligne"><label for="mat">Matière&nbsp;:</label><select id="mat">'+data.mats+'</select></p>\
  <p class="ligne"><label for="rep">Répertoire&nbsp;:</label><select id="rep"></select></p>\
  <p class="ligne"><label for="doc">Document&nbsp;:</label><select id="doc"></select></p>\
  <p class="ligne"><label for="texte">Texte visible&nbsp;:</label><input type="text" id="texte" value="'+sel+'" size="80" data-auto="1"></p>\
  <p class="ligne"><label for="vue">Afficher dans la page (PDF et image uniquement)</label><input type="checkbox" id="vue">\
  <p class="ligne"><label for="largeur">Largeur en %&nbsp;:</label><input type="text" id="largeur" value="100" size="3"></p>\
  <p class="ligne"><label for="format">Format (PDF uniquement)</label><select id="format">\
    <option value="portrait">A4 vertical</option><option value="paysage">A4 horizontal</option><option value="hauteur50">Hauteur 50%</option>\
  </select>');
        $('#fenetre > div:last').show(0);
        // L'attribut data-auto vaut 1 si la valeur du texte est automatiquement
        // modifiée, pour valoir toujours le nom du document. Il est positionné
        // à 0 dès que l'on modifie manuellement l'entrée #texte, redevient égal
        // à 1 si on la vide.
        if ( $('#texte').val().length )
          $('#texte').data('auto',0);
        // Actions sur #doc
        $('#doc').on("change keyup",function(e) {
          if ( e.which == 13 )
            $('#fenetre a.icon-ok').click();
          var texte = $('#doc option:selected').text();
          // Mise à jour automatique du texte si data-auto vaut 1
          if ( $('#texte').data('auto') == 1 )
            $('#texte').val( ( this.value > 0 ) ? texte.substr(0,texte.lastIndexOf('(')-1) : '---' );
          // Visibilité des cases à cocher
          if ( 'pdfjpgpegpng'.indexOf(texte.slice(-4,-1)) > -1 )
            $('#vue').change().parent().show(0);
          else {
            $('#vue, #largeur, #format').parent().hide(0);
            $('#vue').prop('checked',false);
          }
          // Mise à jour de l'aperçu
          majapercu();
        });
        // Actions sur #texte
        $('#texte').on("change keypress",function(e) {
          if ( e.which == 0 )
            return;
          if ( e.which == 13 )
            $('#fenetre a.icon-ok').click();
          // Si vide : modification automatique
          if ( this.value.length == 0 )  {
            $(this).data('auto',1);
            $('#doc').change();
          }
          else  {
            $(this).data('auto',0);
            majapercu();
          }
        });
        // Actions sur #vue
        $('#vue').on("change",function() {
          if ( $('#vue').is(':checked') ) {
            if ( $('#doc option:selected').text().slice(-4,-1) == 'pdf' ) {
              $('#largeur, #format').parent().show(0);
              $('#texte').parent().hide(0);
            }
            else if ( 'jpgpegpng'.indexOf($('#doc option:selected').text().slice(-4,-1)) > -1 ) {
              $('#largeur').parent().show(0);
              $('#format, #texte').parent().hide(0);
            }
          }
          else {
            $('#texte').parent().show(0);
            $('#largeur, #format').parent().hide(0);
          }
          majapercu();
        });
        // Actions sur #format
        $('#format').on("change keyup",function(e) {
          if ( e.which == 13 )
            $('#fenetre a.icon-ok').click();
          majapercu();
        });
        // Actions sur #largeur
        $('#largeur').on("keydown",function(e) {
          if ( e.which == 38 )
            ++this.value;
          else if ( e.which == 40 )
            --this.value;
        }).on("change keyup",function(e) {
          if ( e.which == 0 )
            return;
          if ( e.which == 13 )
            $('#fenetre a.icon-ok').click();
          if ( this.value != $(this).data('valeur') ) {
            $(this).data('valeur', this.value);
            majapercu();
          }
        }).data('valeur',100);
        // Actions sur #rep
        $('#rep').on('change',function() {
          $('#doc').html(data.docs[this.value]).change();
        });
        // Actions sur #mat
        $('#mat').on('change',function() {
          $('#rep').html(data.reps[this.value]).change();
        }).focus().change();
        // Insertion
        $('#fenetre a.icon-ok').on('click',function() {
          if ( $('#doc').val() )  {
            if ( $('#vue').is(':checked') && ( 'pdfjpgpegpng'.indexOf($('#doc option:selected').text().slice(-4,-1)) > -1 ) )
              insert(el,$('#apercu').html(),'','');
            else
              insert(el,'<a href="download?id='+$('#doc').val()+'">','</a>',$('#texte').val());
            $('#fenetre,#fenetre_fond').remove();
          }
        });
        // Sélection a priori d'une matière (programme de colles, cahier de texte,
        // pages associées à une matière)
        $('#mat option').each(function() {
          if ( $('body').data('matiere') == this.value )
            $('#mat').val(this.value).change();
        });
      }
      
      if ( 'mats' in data )
        affichedocs(data);
  });
}

function insertion_lien2(el) {
  popup('<a class="icon-ok" title="Valider"></a><h3>Insertion d\'un lien</h3>\
  <p class="ligne"><label for="texte">Texte visible&nbsp;: </label><input type="text" id="texte" value="'+marqueselection(el)+'" size="80"></p>\
  <p class="ligne"><label for="url">Adresse&nbsp;: </label><input type="text" id="url" value="http://" size="80"></p>\
  <hr><h3>Aperçu</h3><div id="apercu" style="text-align:center;"></div>',true);
  // Modification automatique de l'aperçu
  $('#fenetre input').on("click keyup",function() {
    $('#apercu').html( ( $('#texte').val().length ) ? '<a onclick="return false;" href="'+$('#url').val()+'">'+$('#texte').val()+'</a>' : '[Écrivez un texte visible]');
  }).on("keypress",function(e) {
    if ( e.which == 13 )
      $('#fenetre a.icon-ok').click();
  }).first().keyup().focus();
  // Insertion
  $('#fenetre a.icon-ok').on("click",function() {
    insert(el,'<a href="'+$('#url').val()+'">','</a>',$('#texte').val());
    $('#fenetre,#fenetre_fond').remove();
  });
  // Suppression des attributs liés à la sélection lors de la fermeture
  $('#fenetre a.icon-ferme,#fenetre_fond').on("click",function() {
    marqueselection(el,true);
  });
}

function insertion_tex(el) {
  // Chargement de MathJax si non déjà chargé
  var chargement = ( typeof MathJax === 'undefined'  ) ? '<script type="text/javascript" src="/MathJax/MathJax.js?config=TeX-AMS-MML_HTMLorMML"></script>\
<script type="text/x-mathjax-config">MathJax.Hub.Config({tex2jax:{inlineMath:[["$","$"],["\\\\(","\\\\)"]]}});</script>' : '';
  // Récupération de la sélection et du type de formule éventuelle
  var sel = marqueselection(el);
  var type = 't1';
  if ( sel.length )
    switch ( sel.substring(0,2) ) {
      case '\\[' :
      case '$$'  : type = 't2';
      case '\\(' : sel = sel.substring(2,sel.length-2); break;
      default    : sel = sel.trim('$');
    }
  // Affichage de la fenêtre d'édition/aperçu
  popup(chargement+'<a class="icon-montre" title="Mettre à jour l\'aperçu"></a><a class="icon-ok" title="Valider"></a><h3>Insertion de formules LaTeX</h3>\
  <p>Vous pouvez ci-dessous entrer et modifier une formule LaTeX. L\'aperçu présent en bas sera mis à jour uniquement lorsque vous cliquez sur l\'icône <span class="icon-montre"></span>.</p>\
  <p class="ligne"><label for="t1">La formule est en ligne (pas de retour)</label><input type="radio" name="type" id="t1" value="1"></p>\
  <p class="ligne"><label for="t2">La formule est hors ligne (formule centrée)</label><input type="radio" name="type" id="t2" value="2"></p>\
  <textarea id="formule" rows="3">'+sel+'</textarea>\
  <hr><h3>Aperçu</h3><div id="apercu" style="text-align:center;">[Demandez l\'aperçu en cliquant sur l\'icône <span class="icon-montre"></span>]</div>',true);
  $('#'+type).prop("checked",true);
  $('#formule').focus();
  // Mise à jour de l'aperçu
  $('#fenetre a.icon-montre').on("click",function() {
    if ( $('#formule').val().length ) {
      $('#apercu').html( ( $('#t1').is(":checked") ) ? '$'+$('#formule').val()+'$' : '\\['+$('#formule').val()+'\\]').css('text-align','left');
      MathJax.Hub.Queue(["Typeset",MathJax.Hub,"apercu"]);
    }
    else
      $('#apercu').html('[Écrivez une formule]').css('text-align','center');
  });
  // Insertion
  $('#fenetre a.icon-ok').on("click",function() {
    if ( $('#t1').is(":checked") )
      insert(el,'$','$',$('#formule').val());
    else
      insert(el,'\\[','\\]',$('#formule').val());
    $('#fenetre,#fenetre_fond').remove();
  });
  // Suppression des attributs liés à la sélection lors de la fermeture
  $('#fenetre a.icon-ferme,#fenetre_fond').on("click",function() {
    marqueselection(el,true);
  });
}

// Fonctions lancées par les boutons à insertion directe
function insertion_par1(el)    { insert(el,'<p>','</p>'); }
function insertion_par2(el)    { insert(el,'<div class=\'note\'>','</div>'); }
function insertion_par3(el)    { insert(el,'<div class=\'annonce\'>','</div>'); }
function insertion_retour(el)  { insert(el,'<br>',''); }
function insertion_gras(el)    { insert(el,'<strong>','</strong>'); }
function insertion_italique(el) { insert(el,'<em>','</em>'); }
function insertion_souligne(el) { insert(el,'<u>','</u>'); }
function insertion_exp(el)     { insert(el,'<sup>','</sup>'); }
function insertion_ind(el)     { insert(el,'<sub>','</sub>'); }

// Aide
function aidetexte() {
  popup('<h3>Aide et explications</h3>\
  <p>Il y a deux modes d\'éditions possibles pour éditer un texte&nbsp;: le mode «&nbsp;balises visibles&nbsp;» et le mode «&nbsp;balises invisibles&nbsp;». Il est possible de passer de l\'un à l\'autre&nbsp;:</p>\
  <ul>\
    <li><span class="icon-source"></span> permet de passer en mode «&nbsp;balises visibles&nbsp;» (par défaut), où le texte à taper est le code HTML de l\'article. Ce mode est plus précis. Les boutons aux dessus aident à utiliser les bonnes balises.</li>\
    <li><span class="icon-nosource"></span> permet de passer en mode «&nbsp;balises invisibles&nbsp;», où le texte est tel qu\'il sera affiché sur la partie publique, et modifiable. Ce mode est moins précis, mais permet le copié-collé depuis une page web ou un document Word/LibreOffice.\
  </ul>\
  <p>Une fonction de nettoyage du code HTML, permettant d\'assurer une homogénéité et une qualité d\'affichage optimales, est lancée à chaque commutation entre les deux modes, à chaque clic sur un des boutons disponibles, à chaque copie/coupe de texte et à chaque passage à la ligne.</p>\
  <p>En HTML, toutes les mises en formes sont réalisées par un encadrement de texte entre deux balises&nbsp;: &lt;h3&gt; et &lt;/h3&gt; pour un gros titre, &lt;p&gt; et &lt;/p&gt; pour un paragraphe. Le retour à la ligne simple, qui ne doit exister que très rarement, est une balise simple &lt;br&gt;. Mais les boutons disponibles sont là pour vous permettre de réaliser le formattage que vous souhaitez&nbsp;:</p>\
  <ul>\
    <li><span class="icon-titres"></span>&nbsp;: différentes tailles de titres (fenêtre supplémentaire pour choisir)</li>\
    <li><span class="icon-par1"></span>&nbsp;: paragraphe classique, qui doit obligatoirement encadrer au minimum chaque ligne de texte. Apparaît automatiquement au passage à la ligne si on l\'oublie.</li>\
    <li><span class="icon-par2"></span>&nbsp;: paragraphe important, écrit en rouge</li>\
    <li><span class="icon-par3"></span>&nbsp;: paragraphe très important, écrit en rouge et encadré</li>\
    <li><span class="icon-retour"></span>&nbsp;: retour à la ligne. Identique à un appui sur Entrée, et souvent inutile.</li>\
    <li><span class="icon-gras"></span>&nbsp;: mise en gras du texte entre les balises</li>\
    <li><span class="icon-italique"></span>&nbsp;: mise en italique du texte entre les balises</li>\
    <li><span class="icon-souligne"></span>&nbsp;: soulignement du texte entre les balises</li>\
    <li><span class="icon-omega"></span>&nbsp;: lettres grecques (fenêtre supplémentaire pour choisir)</li>\
    <li><span class="icon-sigma"></span>&nbsp;: symboles mathématiques (fenêtre supplémentaire pour choisir)</li>\
    <li><span class="icon-exp"></span>&nbsp;: mise en exposant du texte entre les balises</li>\
    <li><span class="icon-ind"></span>&nbsp;: mise en indice du texte entre les balises</li>\
    <li><span class="icon-ol"></span>&nbsp;: liste numérotée. Une fenêtre supplémentaire permet de choisir le type (1,A,a,I,i) et la première valeur. Les différentes lignes de la liste sont constituées par les balises &lt;li&gt; et &lt;/li&gt;</li>\
    <li><span class="icon-ul"></span>&nbsp;: liste à puces. Les différentes lignes de la liste sont constituées par les balises &lt;li&gt; et &lt;/li&gt;</li>\
    <li><span class="icon-lien1"></span>&nbsp;: lien d\'un document disponible ici (fenêtre supplémentaire pour choisir)</li>\
    <li><span class="icon-lien2"></span>&nbsp;: lien vers un autre site web (fenêtre supplémentaire pour entre l\'adresse)</li>\
    <li><span class="icon-tex"></span>&nbsp;: insertion de code LaTeX (fenêtre supplémentaire pour le taper)</li>\
  </ul>\
  <p class="tex2jax_ignore">Il est possible d\'insérer du code en LaTeX, sur une ligne séparée (balises \\[...\\] ou balises $$...$$) ou au sein d\'une phrase (balises $...$ ou balises \\(...\\)). Il faut ensuite taper du code en LaTeX à l\'intérieur. La prévisualisation est réalisée en direct.</p>',false);
} 

///////////////////////////////
// Modification des articles //
///////////////////////////////

// Échange vertical de deux éléments
// el1 doit être le plus haut des deux
function echange(el1,el2) {
  if ( el1.length && el2.length ) {
    op1 = el1.css('opacity');
    op2 = el2.css('opacity');
    $('article').css('position','relative');
    el1.css('opacity',0.3); el2.css('opacity',0.3);
    el2.animate({top: el1.position().top-el2.position().top},1000);
    el1.animate({top: (el2.outerHeight(true)+el2.outerHeight())/2},1000,function() {
      el1.css('opacity',op1); el2.css('opacity',op2);
      el1.insertAfter(el2);
      el1.css({'position': 'static', 'top': 0});
      el2.css({'position': 'static', 'top': 0});
    });
  }
}

// Disparition de la partie publique
// Remarque : la page est rechargée dans le cas des répertoires/documents
function cache(el) {
  var parent = el.parent();
  var action = parent.data('action') || $('body').data('action'); 
  if ( action == 'reps' )
    confirmation('Vous allez cacher le répertoire <em>'+( el.siblings('.nom').text() || parent.find('input').val() )+'</em> ainsi que tout son contenu, sous-répertoires et documents qui s\'y trouvent. Ils seront donc tous invisibles.<br>Ensuite, vous pourrez remettre chaque document visible individuellement à l\'aide de l\'icône <span class="icon-montre"></span> sur la ligne de chaque document ou globalement à l\'aide de la même icône sur la ligne d\'un répertoire.<br>Tous les éventuels affichages différés de documents seront supprimés.<br>Utiliser cette fonction revient au même que de régler la protection du répertoire sur «&nbsp;Répertoire invisible&nbsp;» à l\'aide de l\'icône <span class="icon-lock"></span> puis cocher «&nbsp;Propager ce choix d\'accès&nbsp;».',el,function(el) {
      $.ajax({url: 'ajax.php',
              method: "post",
              data: { action:'reps', id:parent.data('id'), matiere:$('body').data('matiere'), cache:1 },
              dataType: 'json',
              el: parent
      });
    });
  else
    $.ajax({url: 'ajax.php',
            method: "post",
            data: { action:action, id:parent.data('id'), matiere:$('body').data('matiere'), cache:1 },
            dataType: 'json',
            el: el,
            fonction: function(el) {
              el.parent().addClass('cache');
              el.removeClass('icon-cache').addClass('icon-montre').off("click").on("click",function() {
                montre($(this));
              }).attr("title","Montrer à nouveau");
              // Cas des possibilités de protection/édition par élément
              el.parent('[data-protection]').data('protection', 32);
              el.parent('[data-edition]').data('edition', 0);
            }
    });
}

// Apparition sur la partie publique
// Remarque : la page est rechargée dans le cas des répertoires/documents
function montre(el) {
  var parent = el.parent();
  var action = parent.data('action') || $('body').data('action'); 
  if ( action == 'reps' )
    confirmation('Vous allez montrer (rendre visible) le répertoire <em>'+( el.siblings('.nom').text() || parent.find('input').val() )+'</em> ainsi que tout son contenu, sous-répertoires et documents qui s\'y trouvent. Ils seront donc tous visibles, avec la même protection que le répertoire affiché sur cette page.<br>Si des documents sont déjà visibles, leur protection sera modifiée. Si un affichage différé a été enregistré, le réglage sera conservé.',el,function(el) {
      $.ajax({url: 'ajax.php',
              method: "post",
              data: { action:'reps', id:parent.data('id'), matiere:$('body').data('matiere'), montre:1 },
              dataType: 'json',
              el: parent
      });
    });
  else
    $.ajax({url: 'ajax.php',
            method: "post",
            data: { action:action, id:parent.data('id'), matiere:$('body').data('matiere'), montre:1 },
            dataType: 'json',
            el: el,
            fonction: function(el) {
              el.parent().removeClass('cache');
              el.removeClass('icon-montre').addClass('icon-cache').off("click").on("click",function() {
                cache($(this));
              }).attr("title","Cacher à nouveau");
              // Cas des possibilités de protection/édition par élément
              el.parent('[data-protection]').data('protection', $('body').data('protection'));
              el.parent('[data-edition]').data('edition', $('body').data('edition'));
            }
    });
}

// Montée
function monte(el) {
  $.ajax({url: 'ajax.php',
          method: "post",
          data: { action:$('body').data('action'), id:el.parent().data('id'), matiere:$('body').data('matiere'), monte:1 },
          dataType: 'json',
          el: el.parent(),
          fonction: function(el) {
            if ( !(el.prev().prev().is('article')) ) {
              el.children('.icon-monte').hide(1000);
              el.prev().children('.icon-monte').show(1000);
            }
            if ( !(el.next().is('article')) ) {
              el.children('.icon-descend').show(1000);
              el.prev().children('.icon-descend').hide(1000);
            }
            echange(el.prev(),el);
          }
  });
}

// Descente
function descend(el) {
  $.ajax({url: 'ajax.php',
          method: "post",
          data: { action:$('body').data('action'), id:el.parent().data('id'), matiere:$('body').data('matiere'), descend:1 },
          dataType: 'json',
          el: el.parent(),
          fonction: function(el) {
            if ( !(el.prev().is('article')) ) {
              el.children('.icon-monte').show(1000);
              el.next().children('.icon-monte').hide(1000);
            }
            if ( !(el.next().next().is('article')) ) {
              el.children('.icon-descend').hide(1000);
              el.next().children('.icon-descend').show(1000);
            }
            echange(el,el.next());
          }
  });
}

// Suppression
function supprime(el) {
  var item = 'un élément';
  var parent = el.parent();
  var action = parent.data('action') || $('body').data('action');
  var matiere = $('body').data('matiere');
  switch ( action ) {
    case 'infos': item = 'une information'; break;
    case 'pages': item = 'la page <em>'+parent.find('h3').text()+'</em>. Les informations qui y sont écrites seront aussi supprimées'; break;
    case 'reps': item = 'le répertoire <em>'+parent.find('.nom').map(function() { return this.textContent || $(this).find('input').val(); }).get(0)+'</em>. <strong>Tous les sous-répertoires et documents qui s\'y trouvent seront aussi supprimés</strong>'; break;
    case 'docs': item = 'le   document <em>'+parent.find('.nom').map(function() { return this.textContent || $(this).find('input').val(); }).get(0)+'</em>'; break;
    case 'progcolles': item = 'le programme de colles de la '+parent.find('.edition').text().toLowerCase(); break;
    case 'cdt': item = 'un élément du cahier de texte'; break;
    case 'cdt-types': item = 'le type de séances <em>'+parent.find('h3').text()+'</em>. <strong>Les éléments du cahier de texte associés à ce type seront aussi supprimés</strong>'; break;
    case 'cdt-raccourcis': item = 'le raccourci de séance <em>'+parent.find('h3').text()+'</em>. Aucun élément du cahier de texte ne sera supprimé'; break;
    case 'notescolles': item = 'une colle du <em>'+parent.parent().find('td').eq(0).text()+'</em>, d\'une durée de '+parent.parent().find('td').eq(3).text()+'. Toutes les notes de cette colle seront supprimées'; break;
    case 'notescollesgestion': item = 'une colle effectuée le '+parent.parent().find('td').eq(1).text()+' par '+parent.parent().find('td').eq(0).text()+' d\'une durée de '+parent.parent().find('td').eq(4).text()+'. Toutes les notes de cette colle seront supprimées'; break;
    case 'matieres': item = 'la matière <em>'+parent.find('h3').text()+'</em>. <p class="note"><strong>ATTENTION&nbsp;: Les programmes de colles, le cahier de texte et les notes correspondantes seront toutes automatiquement supprimées.</strong></p> <p>Les répertoires, les documents, les pages d\'informations spécifiques et les éléments de l\'agenda associés à la matière seront conservés mais ne seront plus associés à une matière&nbsp;: ils seront désormais visibles dans le contexte «&nbsp;général&nbsp;».<br><strong>Si vous souhaitez simplement réinitialiser la matière, ce n\'est pas la bonne méthode</strong>&nbsp;: vous devriez pouvoir faire ce que vous souhaitez avec les possibilités de cette page'; matiere = parent.data('id'); break;
    case 'groupes': item = 'le groupe <em>'+( parent.find('.editable').text() || parent.find('input').first().val())+'</em>. Les utilisateurs concernés ne seront pas supprimés'; break;
    case 'agenda': item = 'un événement de l\'agenda'; break;
    case 'agenda-types': item = 'le type d\'événement <em>'+parent.find('h3').text()+'</em>. <strong>Les événements de l\'agenda associés à ce type seront aussi supprimés</strong>'; break;
    case 'transferts': item = 'le transfert de documents <em>'+parent.children('h3').text()+'</em>. <strong>Tous les documents associés à ce transfert seront automatiquement supprimés</strong>'; break;
  }
  confirmation('Vous allez supprimer XXX.<br>Cette opération n\'est pas annulable.'.replace('XXX',item),el,function(el) {
    $.ajax({url: 'ajax.php',
            method: "post",
            data: { action:action, id:parent.data('id'), matiere:matiere, supprime:1 },
            dataType: 'json',
            el: parent,
            fonction: function(el) {
              if ( action.substring(0,5) == 'notes' )
                el.parent().remove();
              else {
                $('#transferts').find('td[data-id='+el.data('id')+']').parent().remove();
                el.remove();
              }
            }
    });
  });
}

// Apparition de commentaire 
function comms(el) {
  var id = el.parent().data('id');
  $.ajax({url: 'recup.php',
          method: "post",
          data: { action:'commentairescolles', id:id },
          dataType: 'json',
          afficheform: function(data) {
            // Récupération des valeurs et écriture 
            // notes et comms sont des objects, sans méthode forEach
            var notes = data['notes'];
            var comms = data['comms'];
            // Affichage différent en fonction de la page utilisée
            var texte = ( ( $('body').data('action') == 'notescolles' ) ? '<h3>Colle du '+el.parent().siblings()[0].innerText+'</h3>'
                                                                        : '<h3>Colle du '+el.parent().siblings()[1].innerText+', de '+el.parent().siblings()[0].innerText+'</h3>' );
            for ( e in notes ) {
              comm = ( e in comms ) ? decodeURIComponent(window.atob(comms[e])) : 'Pas de commentaire';
              texte += '<p><strong>' + $('#form-notes').find('[data-id='+e+']').text() + '</strong> - ' + notes[e] + '<br>' + comm + '</p>';
            }
            popup(texte);
          }
  });
}

// Ajout de colle : édition et remplacement
function ajoutecolle(el) {
  // Modification de l'article et insertion du formulaire
  var article = el.parent();
  el.before('<a class="icon-annule" title="Annuler"></a><a class="icon-ok" title="Valider"></a>').hide(0);
  var form = $('<form></form>').appendTo(article).html($('#form-ajouteprogcolle').html());
  $('input',form).attr('id',function(){ return this.getAttribute('name'); });
  // Ajout des sélecteurs de disponibilité
  $('#dispo', form).each(function() {
    $('#dispo', form).val( el.parent().data('dispo') || '' ).datetimepicker({ format: 'd/m/Y Ghi', timepicker: true, onShow: function() { this.setOptions({minDate: new Date()}); } });
    $('#affdiff', form).prop("checked",!!el.parent().data('dispo')).on("click change",function() { $('#dispo', form).parent().toggle(this.checked); }).change();
  });
  // Ne pas quitter avec un textarea plein
  $('textarea',form).bloque().textareahtml();
  $('input',form).bloque().entreevalide(form);
  // Impossible de différer l'affichage d'un élément invisible
  $('#cache',form).on("click", function() {
    $('#affdiff',form).parent().toggle(!this.checked);
    if ( this.checked )
      $('#dispo',form).val('').parent().hide(0);
  })
  $('#affdiff',form).on("click", function() {
    $('#cache',form).parent().toggle(!this.checked);
  })
  // Actions des boutons annulation, aide, validation
  $('.icon-annule', article).on("click",function() {
    $('form,.icon-annule,.icon-ok', article).remove();
    article.children().show(0);
    $('a.icon-aide').off("click").on("click",function() {
      popup($('#aide-progcolles').html(),false);
    });
  });
  $('a.icon-aide').off("click").on("click",function() {
    popup($('#aide-ajoute').html(),false);
  });
  $('a.icon-ok', article).on("click",function() {
    // Nettoyage du texte
    $('textarea',form).each(function() {
      this.value = nettoie( ( $(this).is(':visible') ) ? this.value : $(this).next().html() );
    });
    // Envoi
    $.ajax({url: 'ajax.php',
            method: "post",
            data: form.serialize()+'&action=ajout-progcolle&id='+article.data('id')+'&matiere='+$('body').data('matiere'),
            dataType: 'json',
            el: article
    });
  });
}

///////////////////////////////////////
// Formulaires : fonctions générales //
///////////////////////////////////////

// Envoi automatique : pour les formulaires (prefs perso et admin) déjà présents
function valide() {
  var data = 'action='+$('body').data('action');
  // Récupération éventuelle de la matière (pour quelle action ?), de l'identifiant
  if ( $('body').data('matiere') > 0 )
    data += '&matiere='+$('body').data('matiere');
  if ( $(this).parent().data('id') )
    data += '&id='+$(this).parent().data('id');
  $.ajax({url: 'ajax.php',
          method: "post",
          data: data +'&'+ ( $('body').data('action') == 'planning' ? $('form') : $(this).nextAll('form') ).serialize(),
          dataType: 'json',
          el: false,
          fonction: Function.prototype
  }).done(function(data) {
    // Cas spécial de la modification de son adresse électronique :
    // envoi d'un code de confirmation à taper 
    if ( data['etat'] == 'confirm_mail' )  {
      var form = $('[data-id="mail"] form');
      $('p:not(.ligne)',form).remove();
      form.prepend($('<p class="annonce"></p>').html(data['message']));
      $('#mail').attr('readonly',true);
      $('p:hidden',form).show(0).children('input').attr('disabled',false);
    }
  });
}

// Formulaire généré lors des clics sur les boutons généraux
function formulaire() {
  var el = $(this);
  var idform = this.className.split(' ')[0].substring(5);
  var action = $('#form-'+idform).data('action') || $('body').data('action') ;
  // Suppression d'un éventuel contenu épinglé existant
  $('#epingle').remove();
  // Création du nouveau formulaire
  $('<article id="epingle"><a class="icon-ferme" title="Fermer"></a>\
  <a class="icon-aide" title="Aide pour ce formulaire"></a>\
  <a class="icon-ok" title="Valider"></a></article>').prependTo($('section')).append( 
    $('<form></form>').html($('#form-'+idform).html())
  );
  var form = $('#epingle').find('form');
  // Boutons pour les textarea
  $('.edithtml',form).textareahtml();
  // Création de l'identifiant des champs à partir du name
  $('input[name], select[name]:not([multiple])',form).attr('id',function(){ return this.getAttribute('name'); });
  // Récupération éventuelle de l'identifiant
  if ( el.parent().data('id') )
    form.append('<input type="hidden" name="id" value="'+el.parent().data('id')+'">');
  // Actions spécifiques
  switch ( action ) {
    case 'supprime-infos': el.init_supprimeinfos();; break;
    case 'infolock': el.init_lock(); action = 'infos'; break;
    case 'ajout-rep':
    case 'reps': el.init_reps(action); break;
    case 'docs':
    case 'maj-doc':
    case 'ajout-doc': el.init_docs(action); break;
    case 'vide-rep': el.init_viderep(); break;
    case 'download-rep': el.init_downloadrep(); break;
    case 'cdt': form.init_cdt_boutons(); break;
    case 'ajout-cdt-raccourci': form.init_cdt_raccourcis(); break;
    case 'notescolles':
    case 'ajout-notescolles': 
    case 'notescollesgestion': el.init_notes(action); break;
    case 'ajout-agenda': el.init_evenements(); break;
    case 'agendalock': el.init_lock(); action = 'agenda'; break;
    case 'ajout-utilisateurs': form.init_ajout_utilisateurs(); break;
    case 'ajout-groupe': $('.usergrp span',form).on("click", utilisateursgroupe); break;
    case 'transferts':
    case 'ajout-transfert': el.init_transferts(); break;
    case 'voir-transdocs': 
    case 'ajout-transdocs': el.init_transdocs(action); break;
    case 'pages':
    case 'prefsmatiere':
    case 'ajout-cdt-type': form.append('<input type="hidden" name="matiere" value="'+$('body').data('matiere')+'">'); break;
    case 'ajout-page': el.init_page();
  }
  // Pour un nouveau type d'événements de l'agenda (colpick n'existe pas ailleurs)
  $('#couleur',form).each(function() { $(this).colpick(); });
  // Selections multiples (matière, accès)
  $('select[multiple]', form).init_selmult(el);
  // Ajout des sélecteurs de disponibilité
  $('#dispo', form).each(function() {
    $('#dispo', form).val( el.parent().data('dispo') || '' ).datetimepicker({ format: 'd/m/Y Ghi', timepicker: true, onShow: function() { this.setOptions({minDate: new Date()}); } });
    $('#affdiff', form).prop("checked",!!el.parent().data('dispo')).on("click change",function() { 
      $('#dispo', form).parent().toggle(this.checked);
      $('#publi', form).parent().toggle( $('#fichier',form).length && $('#fichier',form)[0].files.length && !this.checked);
    }).change();
    $('#protection', form).next().on("change",function() {
      if ( $('#protection', form).val() == 32 )
        $('#edition,#affdiff,#dispo', form).parent().hide(0);
      else 
        $('#edition,#affdiff',form).change().parent().show(0);
    });
  });
  // Bloquage après modification et envoi par appui sur Entrée
  $('input,select',form).not('.nonbloque').bloque().entreevalide(form);
  $('textarea',form).bloque();
  // Actions des boutons fermeture, aide, validation
  $('#epingle .icon-ferme').on("click",function() { $('#epingle').remove(); });
  $('#epingle a.icon-aide').on("click",function() { popup($('#aide-'+idform).html(),false); });
  form.append('<input type="hidden" name="action" value="'+action+'">');
  // Validation, comportement par défaut
  $('#epingle a.icon-ok').on("click",function() {
    // Nettoyage et synchronisation si besoin
    $('.edithtml',form).each(function() {
      this.value = nettoie( ( $(this).is(':visible') ) ? this.value : $(this).next().html() );
    });
    // Nettoyage des notes à ne pas mettre
    if ( ( action == 'notes' ) || ( action == 'ajout-notes' ) )
      $('#epingle select:not(:visible)').val('x');
    // Envoi
    $.ajax({url: 'ajax.php',
            method: "post",
            data: form.serialize(),
            dataType: 'json'
    });
  });
  $('#epingle').deplace_viewport();
}

// Initialisation d'un select multiple
//  * remplissage si select d'accès (protection/edition)
//  * création d'un faux select avant le vrai
//  * ouverture d'une fenêtre au clic, cochage multiple
//  * mise à jour du faux select à la fermeture
$.fn.init_selmult = function(elem) {
  return this.each(function() {
    var sel = $(this);
    var el = elem ?? sel;
    var nom = this.getAttribute('name').slice(0,-2);
    var mid = $('body').data('matiere') || el.parent().parent().parent().data('matiere');
    // isacces : 0 par défaut (choix des matières associées), 1 pour protection,
    // 2 pour édition, 3 pour acces_transfert
    var isacces = 0;
    if ( nom.indexOf('protection')+1 )   isacces = 1;
    else if ( nom.indexOf('edition')+1 ) isacces = 2;
    else if ( nom == 'accestransfert' )  isacces = 3;
    // Faux élément select de remplacement, correspondant au label, générant au clic une fenêtre de sélection
    $('<select id='+nom+'><option selected hidden></option></select>').insertBefore(sel.hide(0));

    // Initialisation si select d'accès
    if ( isacces )  {
      switch ( isacces ) {
        // Cas de la protection
        case 1:
          var valeur = ( el.parent().is('#icones') ) ? $('body').data('protection') : el.parent().data('protection');
          // Page de gestion des matières
          if ( $('body').data('action') == 'matieres' )  {
            sel.data('n',7).html('<option value="0">Accès public</option><option value="7">Accès aux utilisateurs identifiés</option><option value="1">Invités</option><option value="2">Élèves</option><option value="3">Colleurs</option><option value="4">Lycée</option><option value="5">Professeurs non associés à la matière</option><option value="6">Professeurs associés à la matière</option><option value="33">Fonction désactivée</option>');
            // Si 32, il faut activer 6 et 7, donc passer le test de la sélection initiale
            if ( valeur == 32 )
              valeur = -32;
          }
          // Ressources (infos, pages, reps, docs, progcolles, cdt)
          else 
            sel.data('n',5+!!mid).html('<option value="0">Accès public</option><option value="7">Accès aux utilisateurs identifiés</option><option value="1">Invités</option><option value="2">Élèves</option><option value="3">Colleurs</option><option value="4">Lycée</option>' + ( mid ? '<option value="5">Professeurs non associés à la matière</option>' : '' ) + '<option value="32">'+$(this).data('val32')+'</option>');
          break;
        // Cas de l'édition
        case 2:
          var valeur = ( el.parent().is('#icones') ) ? $('body').data('edition') : el.parent().data('edition');
          sel.data('n',4+!!mid).html( mid ? '<option value="0">Édition par les professeurs de la matière uniquement</option><option value="7">Édition également possible par les utilisateurs</option><option value="2">Élèves</option><option value="3">Colleurs</option><option value="4">Lycée</option><option value="5">Professeurs non associés à la matière</option>' : '<option value="0">Édition par les professeurs uniquement</option><option value="7">Édition également possible par les utilisateurs</option><option value="2">Élèves</option><option value="3">Colleurs</option><option value="4">Lycée</option>' );
          break;
        // Cas de l'accès à un nouveau transfert
        case 3:
          sel.data('n',3+!!mid).html( mid ? '<option value="0">Professeurs de la matière uniquement</option><option value="7">Accès également aux utilisateurs</option><option value="2">Colleurs</option><option value="3">Lycée</option><option value="4">Professeurs non associés à la matière</option>' : '<option value="0">Professeurs uniquement</option><option value="7">Accès également aux utilisateurs</option><option value="2">Colleurs</option><option value="3">Lycée</option>');
          var valeur = 0;
      }
      // Déplacement du "name" pour envoyer une unique valeur par le faux select
      sel.removeAttr('name').prev().attr('name',nom);
      // Sélection initiale
      sel.val( ( valeur == 0 ) || ( valeur > 31 ) ? valeur : [7,6].concat([1,2,3,4,5].filter(function(v,a) { return ((valeur-1)>>a & 1) == isacces-1; })) );
    }
    // Fin de l'initialisation
    majselect(sel);
    
    // Fonction de mise à jour du texte affiché 
    function majselect(sel) {
      // Option du faux select, qui permet l'affichage
      // Désélection pour raffraichissement, avant resélection à la fin
      var aff = sel.prev().children().prop('selected',false);
      // Si accès, modification du texte et de la valeur
      if ( isacces ) {
        var valeurs = sel.val();
        // Modification du texte. "Tout utilisateur identifié" si 6 valeurs pour protection, 5 pour édition 
        switch ( valeurs.length ) {
          case 0: aff.text('Choisir ...'); break;
          case 1: aff.text($('option:selected',sel).text()); break
          case sel.data('n'): aff.text('Tout utilisateur identifié'); break;
          default:
            var texte = $('option:selected',sel).filter(function() { return this.value < 6; }).map(function() { return this.textContent.split(' ')[0]; }).get().join(', ');
            // Cas de la fonction invisible
            if ( !texte.length )
              texte = 'Professeurs associés à la matière seulement';
            // Cas particulier de la protection des transferts
            if ( ( isacces == 3 ) && $('option[value=7]',sel).prop("selected") )  {
              texte = "Professeurs, " + texte + ( texte.indexOf('Professeurs')>0 ? ' non associés' : ''); 
            }
            aff.text(texte.replace(/,([^,]+)$/, " et$1"));
        }
        // Modification de la valeur. Protection : 32-somme(2^(v-1)),
        // édition : 1+somme(2^(v-1)), transfert : somme(2^(v-1))
        if ( valeurs.length == 1 )
          aff.val(valeurs[0]);
        else {
          var somme = valeurs.reduce( function(acc,v) { return acc + ( v<6 ? Math.pow(2,(v-1)) : 0 ); },0);
          // Pour les protections de transferts, il faudra ajouter 2 car les élèves sont silencieusement autorisés
          aff.val( isacces == 1 ? 32-somme : 3-isacces+somme );
        }
      }
      // Autres selects : modification uniquement du texte
      else
        aff.text( sel.val().length ? $('option:selected',sel).map(function() { return this.textContent; }).get().join(', ').replace(/,([^,]+)$/, " et$1") : 'Choisir ...');
      // Resélection pour raffraichissement
      aff.prop('selected',true);
    }
    
    // Génération de la fenêtre de sélection au clic sur le faux select
    sel.prev().attr('disabled',sel.attr('disabled')).on("mousedown",function(e)  {
      e.preventDefault();
      this.blur();
      // Fenêtre de sélection
      popup('<a class="icon-ok" title="Valider ce choix"></a><h3>'+sel.prev().prev().text().replace(':','')+'</h3><table id="selmult">'
        +$('option',sel).map(function() {
          return '<tr'+(this.selected?' class="sel"':'')+'><td>'+this.textContent+'</td><td><input type="checkbox" '+(this.selected?'checked ':'')+'value="'+this.value+'"></td></tr>'
        }).get().join('')
        +'</table>',true);
      var f = $('#fenetre');
      // Différenciation des lignes si accès (pour cochage multiple et css)
      if ( isacces ) {
        $('input', f).filter(function() { return this.value == 0 || this.value > 6; }).parent().parent().addClass('categorie');
        $('tr:not(.categorie)', f).addClass('element');
        $('input[value=6]', f).prop("disabled",true);
        // Décochage automatique
        $('input', f).on("click",function() {
          if ( ( this.value == 0 ) || ( this.value > 31 ) ) {
            $(this).prop("checked",true).parent().parent().siblings().find('input[type=checkbox]').prop("checked",false).change();
            $('input[value=6]', f).prop("disabled",false);
          }
          else {
            $('input[value=0],input[value=32],input[value=33]', f).prop("checked",false);
            $('input[value=6]', f).prop({"disabled":true,"checked":true});
            $('input[value=7]', f).prop("checked",true);
            // Cochage multiple si on clique sur "Utilisateurs identifiés"
            if ( this.value == 7 )
              $('tr:not(.categorie) input', f).prop("checked",true)
            // S'il ne reste que la case 7 : on passe en annulation
            if ( $('input:checked', f).length == 1 ) 
              $('input[value=32],input[value=33],input[value=0]:first', f).click();
            $('input', f).change();
          }
        });
      }
      // Cochage multiple si matières 
      else {
        $('#selmult', f).prepend('<tr class="categorie"><th></th><th><a class="icon-cocher"></a></th></tr>');
        // Si choix 0 "Pas de matière"
        $('.icon-cocher', f).on("click", cocher_utilisateurs );
        $('input[value=0]',f).on("click", function() { if ( this.checked )  $('input:checked',f).not('[value=0]').prop("checked",false).change(); });
        $('input[value!=0]',f).on("click", function() { $('[value=0]',f).prop("checked",false).change(); });
      }
      // Clic sur toute la ligne
      $('tr', f).on("click",function(e) {
        if ( !$(e.target).is('input') )
          $(this).find('input').click();
      });
      // Mise en évidence
      $('input', f).on("change",function() { $(this).parent().parent().toggleClass('sel',this.checked); });
      // Validation
      $('.icon-ok', f).on("click",function() {
        // Mise à jour du select : valeur passée sous forme d'array
        sel.val( $('input:checked', f).map(function() { return this.value; }).get() );
        // Mise à jour de l'affichage
        majselect(sel);
        $('#fenetre, #fenetre_fond').remove();
        sel.change(); // Utile pour la liste de documents au transfert de copies
        sel.prev().focus(); // Utile pour la validation par appui sur entrée
      });
    });
  });
}

/////////////////////////////////////////
// Formulaires : facilités spécifiques //
/////////////////////////////////////////

// Facilités du formulaire de suppression multiple des informations
// Code similaire (fusionnable) avec init_viderep
$.fn.init_supprimeinfos = function() {
  var form = $('#epingle form');
  var table = $('#epingle table');
  $('article:not(#epingle)').find('h3.titreinfos').each(function() {
    var el = $(this);
    var cache = ( el.parent().hasClass('cache') ) ? ' class="cache"' : '';
    table.append('<tr'+cache+'><td>'+(el.text() || 'Information sans titre')+'</td><td class="icones"><input type="checkbox" name="infos[]" value="'+el.parent().data('id')+'"></td></tr>');
  });
  $('input',table).on("change",function() {
    $(this).parent().parent().toggleClass('sel',this.checked);
  });
  $('#infoscachees',form).on("click",function() {
    $('tr.cache input',table).prop('checked',this.checked).change();
  });
  $('.icon-cocher',form).on("click",function() {
    $(this).toggleClass('icon-cocher icon-decocher');
    $('input',form).prop('checked',$(this).hasClass('icon-decocher')).change();
  });
  $('tr',table).find('td:not(:last-child)').on("click",function() {
    $(this).parent().find('input').click().change();
  });
}

// Facilités du formulaire de modification des accès aux informations et à l'agenda
$.fn.init_lock = function() {
  var article = $('#epingle');
  // Récupération de la protection de la page pour propagation
  $('input[type="button"]',article).on("click",function() {
    $('[multiple]',article).each(function() {
      $(this).attr('name',$(this).prev().remove().attr('name')+'[]').init_selmult($('#icones .icon-prefs')); 
    });
  });
}

// Facilités du formulaire de modification des répertoires
// Attention, this est le bouton cliqué
$.fn.init_reps = function(action) {
  var el = $(this);
  var form = $('#epingle form');
  if ( action != 'ajout-rep' ) {
    // Nom du répertoire
    var nom = el.siblings('.nom').text() || el.parent().find('input').val() || el.parent().data('nom');
    // Remplissage des formulaires
    $('em', form).text(nom);
    $('#nom', form).val(nom);
    $('#menurep', form).prop('checked',el.parent().data('menu'));
    // Désactivation des déplacements impossibles
    $('[data-parents*=",' + el.parent().data('id') + ',"]', form).prop('disabled', true);
  }
  $('#download', form).val(el.parent().data('zip'));
}

// Facilités du formulaire de modification des documents
// Attention, this est le bouton cliqué
// action vaut docs ou ajout-docs
$.fn.init_docs = function(action) {
  var el = $(this);
  var form = $('#epingle form').addClass('formdoc');
  // Nom : du document si modification, du répertoire parent si ajout
  var nom = el.siblings('.nom').text() || el.parent().find('input').val() || el.parent().data('nom');
  $('em', form).text(nom);
  $('#nom', form).val(nom);
  // On ne continue que pour les envois de fichiers
  if ( action == 'docs' )
    return
  // Si ajout : modification automatique des saisies de noms
  if ( action == 'ajout-doc' )
    $('input[type="file"]', form).attr('id','fichier').on('change',function() {
      var fichiers = this;
      $('input[id^="nom"]', form).parent().remove();
      for (var i = 0, n = fichiers.files.length, f = ''; i < n; i++) {
        $('.ligne',form).last().after('<p class="ligne"><label for="nom'+i+'">Nom à afficher'+(n>1 ? ' (fichier '+(i+1)+')' : '')+'&nbsp;: </label><input type="text" name="nom[]" id="nom'+i+'" value="" size="50"></p>');
        f = fichiers.files[i].name;
        $('#nom'+i, form).val(f.substring(f.lastIndexOf('\\')+1,f.lastIndexOf('.')) || f).entreevalide(form);
      }
    });
  // Envoi du fichier -- redéfinit le comportement par défaut défini par formulaire()
  $('#epingle a.icon-ok').removeClass('icon-ok').addClass('icon-envoidoc').on("click",function() {
    // Test de connexion
    // Si reconnect() appelée, le paramètre connexion sert à obtenir un retour
    // en état ok/nok pour affichage si nok. Si ok, on réécrit le message. 
    $.ajax({url: 'ajax.php',
            method: "post",
            data: 'verifconnexion=1',
            dataType: 'json',
            el: '',
            fonction: function(el) {
              // Si transfert, pas d'affichage dans le div de log
              $('#log').hide(0);
              // Envoi réel du fichier ou des données
              var data = new FormData(form[0]);
              // Envoi
              $.ajax({url: 'ajax.php',
                      xhr: function() { 
                        // Évolution du transfert si fichier transféré
                        var xhr = $.ajaxSettings.xhr();
                        if ( xhr.upload && ( $('#fichier')[0].files.length > 0 ) ) {
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
}

// Facilités du formulaire de suppression multiple dans un répertoire
// Code similaire (fusionnable) avec init_supprimeinfos
$.fn.init_viderep = function() {
  var form = $('#epingle form');
  var table = $('#epingle table');
  $('section > p[data-id]').each(function() {
    var el = $(this);
    var cache = ( el.hasClass('nodispo') ) ? ' class="cache"' : '';
    if ( el.hasClass('rep') )
      table.append('<tr'+cache+'><td><span class="icon-rep"></span>&nbsp;'+el.find('span.nom').text()+'</td><td class="icones"><input type="checkbox" name="reps[]" value="'+el.data('id')+'"></td></tr>');
    else
      table.append('<tr'+cache+'><td><span class="icon-doc"></span>&nbsp;'+el.find('span.nom').text()+'</td><td class="icones"><input type="checkbox" name="docs[]" value="'+el.data('id')+'"></td></tr>');
  });
  $('input',table).on("change",function() {
    $(this).parent().parent().toggleClass('sel',this.checked);
  });
  $('#docscaches',form).on("click",function() {
    $('tr.cache input',table).prop('checked',this.checked).change();
  });
  $('.icon-cocher',form).on("click",function() {
    $(this).toggleClass('icon-cocher icon-decocher');
    $('input',form).prop('checked',$(this).hasClass('icon-decocher')).change();
  });
  $('tr',table).find('td:not(:last-child)').on("click",function() {
    $(this).parent().find('input').click().change();
  });
}

// Facilités du formulaire de téléchargement de répertoire
$.fn.init_downloadrep = function() {
  var form = $('#epingle form');
  var table = $('#epingle table');
  var ziprep = $('.topbarre .icon-downloadrep').data('zip');
  $('p.zip'+ziprep, form).siblings('p:not(.ligne)').hide(0);
  $('section > p[data-id]').each(function() {
    var el = $(this);
    var zip = el.data('zip') ?? ziprep ; // Pour les docs, on prend celui du répertoire parent
    var dl = ( !zip ? 'Personne sauf vous' : ( zip == 1 ) ? 'Les connectés' : 'Tous les visiteurs' );
    var cache = ( el.hasClass('nodispo') ) ? ' class="cache"' : '';
    if ( el.hasClass('rep') )
      table.append('<tr'+cache+'><td><span class="icon-rep"></span>&nbsp;'+el.find('span.nom').text()+'</td><td>'+dl+'</td><td class="icones"><input type="checkbox" name="reps[]" value="'+el.data('id')+'"></td></tr>');
    else
      table.append('<tr'+cache+'><td><span class="icon-doc"></span>&nbsp;'+el.find('span.nom').text()+'</td><td>'+dl+'</td><td class="icones"><input type="checkbox" name="docs[]" value="'+el.data('id')+'"></td></tr>');
  });
  $('input',table).on("change",function() {
    $(this).parent().parent().toggleClass('sel',this.checked);
    if ( this.checked && $(this).parent().parent().hasClass('cache') )
      $('#docscaches',form).prop('checked',false);
  });
  // Ne *pas* inclure les docs cachés, présents dans la liste ou dans les sous-répertoires
  $('#docscaches',form).on("click",function() {
    if ( this.checked )
      $('tr.cache input',table).prop('checked',false).change().prop('disabled',true);
    else
      $('tr.cache input',table).prop('disabled',false);
  });
  $('.icon-cocher',table).on("click",function() {
    $(this).toggleClass('icon-cocher icon-decocher');
    $('input',table).not(':disabled').prop('checked',$(this).hasClass('icon-decocher')).change();
  });
  $('tr',table).find('td:not(:last-child)').on("click",function() {
    $(this).parent().find('input').click().change();
  });
  
  // Changement d'icône pour éviter le comportement par défaut de formulaire()
  $('#epingle .icon-ok').removeClass('icon-ok').addClass('icon-download').on("click",function() {
    if ( $('input:checked',table).length == 0 ) {
      affiche('<p>Aucune case n\'est cochée.</p>','nok');
      return
    }
    
    // On positionne un marqueur d'utilisation de fonction asynchrone pour 
    // modifier le comportement de ajaxStop.
    $('body').data('async',true);
    affiche('Récupération de la liste des documents','ok');
    $.ajax({url: 'recup.php',
            method: "post",
            data: 'id='+$('#icones').data('id')+'&'+form.serialize(),
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
                    fichiers[i] = await fetch('download?zip&r='+$('#icones').data('id')+'&d='+ids[i]+'&verif=' + verifs[i]).then(async function(response) {
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
}

// Facilités du formulaire de modification des propriétés des éléments de cahier
// de texte, utilisé par transformecdt() (élément existant) et formulaire()
// (nouvel élément)
$.fn.init_cdt_boutons = function() {
  var form = this.append('<input type="hidden" name="matiere" value="'+$('body').data('matiere')+'">');
  $('#jour,#pour',form).datetimepicker({ format: 'd/m/Y', timepicker: false });
  $('#h_debut',form).datetimepicker({ format: 'Ghi', datepicker: false,
    onClose: function(t,input) {
      $('#h_fin',form).val(function(i,v){ return v || ( input.val().length ? (parseInt(input.val().slice(0,-3))+2)+input.val().slice(-3) : ''); });
    }
  });
  $('#h_fin',form).datetimepicker({ format: 'Ghi', datepicker: false });
  // Ajout du zéro devant les dates et heures à 1 chiffre
  var zero = function(n) {
    return ( String(n).length == 1 ) ? '0'+n : String(n);
  }
  // Action lancée à la modification du raccourci
  $('#raccourci',form).on('change keyup',function() {
    if ( this.value == '0' )
      return ;
    var valeurs = raccourcis[this.value];
    for ( var cle in valeurs ) {
      // Modification de la date (j=1->6 : lundi/mardi précédents ; j=8->13 : suivants)
      // t.getDate()-t.getDay() est la date du dimanche précédent, auquel on
      // ajoute j%7 +/- 7 en fonction de où on est dans la semaine
      if ( cle == 'jour' ) {
        var t = new Date;
        var j = parseInt(valeurs['jour']);
        if ( j%7 != t.getDay() )
          t.setDate( t.getDate() - t.getDay() + j - ( j%7 < t.getDay() ? 0 : 7 ) );
        $('#jour',form).val(zero(t.getDate())+'/'+zero(t.getMonth()+1)+'/'+t.getFullYear());
      }
      // Modification des autres champs
      else
        $('#'+cle,form).val(valeurs[cle]);
    }
    // Remplissage du textarea uniquement si nouvel élément
    $('textarea',form).val(valeurs['template']);
    // Pour éviter d'être remis à zéro immédiatement
    $(this).data('modif',1);
    // Modifie les champs visibles
    $('#tid',form).change();
  }).data('modif',0);
  // Action lancée à la modification du type de séance
  $('#tid',form).on('change keyup',function() {
    switch ( parseInt(seances[this.value]) ) {
      case 0:
        $('#h_debut,#demigroupe',form).parent().show(0);
        $('#h_fin,#pour',form).parent().hide(0);
        break;
      case 1:
        $('#h_debut,#h_fin,#demigroupe',form).parent().show(0);
        $('#pour',form).parent().hide(0);
        break;
      case 2:
        $('#h_debut,#h_fin',form).parent().hide(0);
        $('#pour,#demigroupe',form).parent().show(0);
        break;
      case 3:
        $('#h_debut,#h_fin,#pour',form).parent().hide(0);
        $('#demigroupe',form).parent().show(0);
        break;
      default:
        $('#h_debut,#h_fin,#pour,#demigroupe',form).parent().hide(0);
    }
    // Mise à jour du champ de raccourci
    $('#jour',form).change();
  });
  // Action lancée à la modification des autres champs
  $('input,#demigroupe',form).on('change keyup',function() {
    // Remise à zéro du raccourci s'il n'a pas été modifié immédiatement
    if ( $('#raccourci',form).data('modif') == 0 )
      $('#raccourci',form).val(0);
    else
      $('#raccourci',form).data('modif',0);
  });
  // Impossible de différer l'affichage d'un élément invisible
  $('#cache',form).on("click", function() {
    $('#affdiff',form).parent().toggle(!this.checked);
    if ( this.checked )
      $('#dispo',form).val('').parent().hide(0);
  })
  $('#affdiff',form).on("click", function() {
    $('#cache',form).parent().toggle(!this.checked);
  })
  // Focus sur le premier champ et modification initiale
  $('select:first',form).focus();
  $('#tid',form).change();
}

// Facilités du formulaire de modification des propriétés des raccourcis de
// cahier de texte, utilisé sur les éléments de classe cdt-raccourcis
// Lancé par formulaire() pour un nouveau raccourci, mais directement au
// chargement de la page pour les raccourcis existants.
$.fn.init_cdt_raccourcis = function() {
  this.each(function() {
    var form = $(this).append('<input type="hidden" name="matiere" value="'+$('body').data('matiere')+'">');
    $('[id^="h_d"]',form).datetimepicker({ format: 'Ghi', datepicker: false,
      onClose: function(t,input) {
        $('[id^="h_f"]',form).val(function(i,v){ return v || ( input.val().length ? (parseInt(input.val().slice(0,-3))+2)+input.val().slice(-3) : ''); });
      }
    });
    $('[id^="h_fin"]').datetimepicker({ format: 'Ghi', datepicker: false });
    // Action lancée à la modification du type de séance
    $('[id^="type"]',form).on('change keyup',function() {
      switch ( parseInt(seances[this.value]) ) {
        case 0:
          $('[id^="h_d"],[id^="dem"]',form).parent().show(0);
          $('[id^="h_f"]',form).parent().hide(0);
          break;
        case 1:
          $('[id^="h_d"],[id^="h_f"],[id^="dem"]',form).parent().show(0);
          break;
        case 2:
        case 3:
          $('[id^="h_d"],[id^="h_f"]',form).parent().hide(0);
          $('[id^="dem"]',form).parent().show(0);
          break;
        default:
          $('[id^="h_d"],[id^="h_f"],[id^="dem"]',form).parent().hide(0);
      }
      // Après change() on doit annuler bloque() et le remettre 
    }).change().bloque().prev().addBack().removeClass('nepassortir');
    // Blocage sur le textarea
    $('textarea',form).bloque().textareahtml();
  });
}

// Facilités spécifiques du formulaire d'édition des notes
$.fn.init_notes = function(action) {
  var el = $(this);
  // Matière utile seulement pour l'ajout de colle et la gestion du prof
  var form = $('#epingle form').append('<input type="hidden" name="matiere" value="'+$('body').data('matiere')+'">');
  var table = $('table', form).html($('#form-notes table').html());
  $('input[name="comms"]',table).attr('id','comms');
  
  // Génération des select de notes
  $('tr[data-id]',table).append('<td>'+$('#form-notes div').html()+'</td>');
  $('select',table).attr('name',function() {
    return 'e'+$(this).parent().parent().data('id');
  });

  // Fonction de création d'un commentaire, et remplissage éventuel
  // À appliquer sur le tr contenant l'élève correspondant
  // Appel par each : index,element,données ext
  function nouveau_comm(i,e,v) {
    // On prévient l'ajout d'un commentaire si déjà présent
    if ( !$(e).next().is('.comms') )
      $(e).after('<tr class="comms"><td colspan="2"><textarea name="c'+$(e).data('id')+'" placeholder="Ajouter un commentaire ici">'+ ( v ?? '' ) +'</textarea></td></tr>');
  }
  
  // On cache tous les élèves a priori s'il y a des groupes
  if ( $('input:checkbox',table).length > 1 ) {
    $('tr[data-id]',form).hide(0);
    // Clic sur les groupes
    $('input:checkbox', table).on("change",function() {
      // Case "tous les élèves" cochée
      if ( $('input:checkbox', table).last().prop('checked') )
        $('tr[data-id]',form).show(0);
      // Sinon, seulement les élèves des groupes sélectionnés
      else {
        // On cache tous les élèves sauf ceux ayant déjà une note pour l'édition
        // de notes déjà saisies (lignes repérées par la classe "orig")
        $('tr[data-id]:not(.orig)',table).hide(0);
        // On montre ceux qui ont une note
        $('input:checked', table).each(function() { this.value.split(',').forEach( function(id) { $('tr[data-id="'+id+'"]',form).show(0); }); });
      }
      // Gestion des commentaires
      if ( $('#comms',form).is(':checked') )  {
        $('tr[data-id]:not(:visible) + tr.comms',form).remove();
        $('tr[data-id]:visible',form).not('.dejanote').each(nouveau_comm);
      }
    });
  }
  
  // Clic automatique sur les lignes du tableau
  $('tr td:first-child',table).on("click",function() {
    $(this).parent().find("input").click();
  });
  
  // Fonction d'initialisation de la case jour
  function creer_date(d) { 
    return new Date( d.replace(/(.{2})\/(.{2})\/(.{4})/,function(tout,x,y,z) { return z+'-'+y+'-'+x; }) );
  }
  var debutannee  = creer_date( $('#form-ajoute option').eq(1).data('date') );
  var finannee = new Date( creer_date( $('#form-ajoute option').last().data('date') ).getTime()+6*86400000 );
  function init_jour(sid) {
    var sid = sid ?? 0;
    var debut = ( sid == 0 ) ? debutannee : creer_date( $('#form-ajoute option[value="'+sid+'"]').data('date') );
    var fin = ( sid == 0 || sid == $('#form-ajoute option').last().val() ) ? finannee : new Date( creer_date( $('#form-ajoute option[value="'+sid+'"]').next().data('date') ).getTime()-86400000 );
    $('#jour',form).datetimepicker({ minDate: debut, maxDate: fin });
    var jour = ( $('#jour').val() ) ? creer_date( $('#jour').val() ) : new Date( debutannee.getTime()-86400000 );;
    if ( ( jour < debut ) || ( jour > fin ) ) {
      debut = debut.toJSON();
      $('#jour').val(debut.substr(8,2)+'/'+debut.substr(5,2)+'/'+debut.substr(0,4));
    }
  }

  // Fonction de marquage des déjà notés pour en prévenir la notation, utilisée
  // à l'initialisation et à la modification de semaine
  function marque_dejanotes(sid) {
    if ( sid == 0 )
      return true;
    // On ne désactive que si on n'est pas en préparation à l'oral
    // Le select des semaines est, pour l'ajout comme la modification, disponible
    // dans le formulaire en fin de fichier
    var desactive = !$('#form-ajoute option[value='+sid+']').data('oraux');
    // Récupération des déjà notés par les autres colleurs
    dejanotesautres[sid].split(',').forEach( function(id) {
      $('tr[data-id="'+id+'"]', form).toggleClass('dejanote',desactive).find('td').first().text(function() { return this.textContent+' (noté(e) par un autre colleur)'; });
    });
    // Récupération des déjà notés par l'utilisateur
    dejanotesperso[sid].split(',').forEach( function(id) {
      $('tr[data-id="'+id+'"]:not(.orig)', form).toggleClass('dejanote',desactive).find('td').first().text(function() { return this.textContent+' (déjà noté(e) par vous-même)'; });
    });
    // Désactivation et effacement des valeurs
    $('.dejanote select').prop('disabled',true).val('x');
  }
  
  // Facilités datetimepicker pour les jours, la durée
  $('#jour',form).datetimepicker({ format: 'd/m/Y', timepicker: false });
  // Rattrapage possible sur toute l'année
  $('#rattrapage',form).datetimepicker({ format: 'd/m/Y', timepicker: false, minDate: debutannee, maxDate: finannee });
  // Utile pour les séances sans note ; pour les colles, case modifiée automatiquement 
  $('#duree',form).datetimepicker({ format: 'Ghi', datepicker: false, defaultTime: '0h00', step: 10 }).on("change",function() { $(this).removeClass('auto'); });

  // Mise à jour de la durée à chaque nouvelle note
  // Seulement sur notescolles.php, pas sur notescolles-gestion.php
  $('select', table).on("change keyup",function() {
    if ( typeof heurescolles != 'undefined' )  {
      var nb = $('table select:visible',form).filter(function() { return this.value != "x"; }).length;
      var duree = heurescolles ? 60*Math.ceil(nb*dureecolles/60) : nb*(dureecolles || 20);
      $('#duree').val( (duree/60|0)+'h'+(duree%60||'') );
    }
  });

  // Activation/désactivation des commentaires
  $('#comms',form).on("click",function() {
    if ( this.checked )
      $('tr[data-id]:visible',form).not('.dejanote').each(nouveau_comm);
    else
      $('.comms',form).remove();
  });

  switch ( action ) {
    // Si nouvelles notes : gestion du changement de semaine et de la possibilité de séance de TD
    case 'ajout-notescolles': {
      $('#description').parent().hide(0);
      // Changement de semaine
      $('#sid').on("change keyup",function() {
        // Nettoyage des déjà notés précédents
        $('td:first-child').text(function() {
          return this.textContent.replace(' (noté(e) par un autre colleur)','').replace(' (déjà noté(e) par vous-même)','');
        });
        $('.dejanote').removeClass('dejanote').find('select').prop('disabled',false);
        // Marquage des déjà notés
        marque_dejanotes($('#sid').val());
        // Initialisation du jour
        init_jour($('#sid option:selected').val() || 0);
      }).change();
      // Gestion des séances de TD
      $('#td',form).on("change keyup",function() {
        if ( this.checked ) {
          $('h3',form).text('Ajouter une séance de TD sans note');
          $('#sid',form).parent().hide(0);
          $('#jour',form).prev().text('Jour :');
          $('#rattrapage',form).parent().hide(0);
          $('#description',form).parent().show(0);
          $('#duree',form).prop('readonly',false).prev().text('Durée :');
          table.hide(0);
          init_jour();
        }
        else {
          $('h3',form).text('Ajouter des notes de colles');
          $('#sid',form).parent().show(0);
          $('#jour',form).prev().text('Jour dans le colloscope :');
          $('#rattrapage',form).parent().show(0);
          $('#description',form).parent().hide(0);
          $('#duree',form).prop('readonly',true).prev().text('Durée (modifiée automatiquement) :');
          table.show(0);
          init_jour($('#sid option:selected').val() || 0);
        }
      });
      break;
    }
  
    // Si édition de notes : affichage des notes modifiables, ajout ou modification
    // seulement pour les colles non encore relevées. 
    case 'notescolles': {
      var tr = el.parent().parent();
      // Initialisation du jour, de la durée
      $('#jour',form).val($('td',tr).eq(0).text().replace(/(.{6})(.{2})/,function(tout,x,y) {return x+'20'+y; }));
      if ( $('td',tr).eq(1).text().length > 1 )
        $('#rattrapage',form).val($('td',tr).eq(1).text().replace(/(.{6})(.{2})/,function(tout,x,y) { return x+'20'+y; }));
      $('#duree',form).val($('td',tr).eq(3).text().replace(/.*m/,function(s) {return '0h'+s.slice(0,-1); }));

      // Cas des colles classiques : semaine spécifiée
      if ( el.data('sid') ) { 
        $('#description',form).parent().remove();
        // Initialisation du jour (limites)
        var sid = el.data('sid');
        init_jour(sid);
        // Affichage des notes déjà mises. Les lignes sont marquées avec la classe
        // orig pour rester affichées lors du clic sur une checkbox de groupe, mais
        // ce marquage est supprimé à la première modification de la note
        // Ces données peuvent être des entiers si un seul élève -> on utilise toString
        var eleves = el.data('eleves').toString().split('|');
        var notes = el.data('notes').toString().split('|');
        eleves.forEach( function(eleve,i) { 
          $('tr[data-id="'+eleve+'"]',form).addClass('orig').show(0).find('select').val(notes[i])
            .on('change',function() { $(this).parent().parent().removeClass('orig'); });
        });
        // Initialisation des commentaires
        $('#comms',form).parent().parent().addClass('orig');
        if ( tr.find('u').length )  {
          $('#comms',form).prop('checked',true);
          // Récupération
          $.ajax({url: 'recup.php',
                  method: "post",
                  data: { action:'commentairescolles', id:el.parent().data('id') },
                  dataType: 'json',
                  attente: 'Récupération des commentaires',
                  afficheform: function(data) {
                    var commentaires = data['comms'];
                    eleves.forEach( function(eleve) { 
                      $('tr[data-id="'+eleve+'"]',form).each( function(i,e) { nouveau_comm(i,e,decodeURIComponent(window.atob(commentaires[eleve] || ''))); });
                    });
                  }
          });
        }
        // Initialisation du titre
        $('h3',form).text('Modifier des notes - semaine du '+$('select[name="sid"] option[value="'+sid+'"]').text().split(' ').slice(0,3).join(' '));
        // Si colle non déjà relevée, on peut tout modifier et marquage des déjà notés de la semaine
        if ( $('td',tr).eq(4).text().length == 1 )
          marque_dejanotes(sid);
        // Si colle déjà relevée, on ne peut pas ajouter/supprimer des notes
        else {
          $('tr:not(.orig), .orig option[value="x"]',table).remove();
          form.append('<p>Cette colle a déjà été relevée&nbsp;: il est impossible de modifier quels élèves ont été interrogés. Vous pouvez corriger la date et l\'heure (dans la limite de la semaine enregistrée) ou les notes ou commentaires que vous avez saisis. Vous pouvez aussi mettre une note à un élève initialement absent qui a rattrapé sa colle.</p>');
        }
      }
      // Pas de note d'élève donc séance de TD : on supprime le tableau et on récupère la description
      else {
        table.remove();
        $('h3',form).text('Modifier une séance de TD sans note');
        $('#jour',form).prev().text('Jour :');
        $('#rattrapage',form).parent().remove();
        $('#description',form).val($('td',tr).eq(2).text());
        // Si colle non encore relevée, on peut encore modifier la durée
        if ( $('td',tr).eq(4).text().length == 1 )
          $('#duree',form).prop('readonly',false).prev().text('Durée :');
        else
          form.append('<p>Cette séance a déjà été relevée&nbsp;: il est impossible de modifier sa durée. Vous pouvez corriger la date, l\'heure ou la description.</p>');
      }
      break;
    }
    
    // Si on est sur notescolles-gestion.php, modifications uniquement
    case 'notescollesgestion' : {
      var tr = el.parent().parent();
      // Initialisation des premiers champs
      $('#colleur',form).val($('td',tr).eq(0).text());
      $('#jour',form).val($('td',tr).eq(1).text().replace(/(.{6})(.{2})/,function(tout,x,y) {return x+'20'+y; }));
      if ( $('td',tr).eq(2).text().length > 1 )
        $('#rattrapage',form).val($('td',tr).eq(2).text().replace(/(.{6})(.{2})/,function(tout,x,y) { return x+'20'+y; }));
      $('#duree',form).val($('td',tr).eq(4).text().replace(/.*m/,function(s) {return '0h'+s.slice(0,-1); }));
      // Si colle non encore relevée, on peut encore modifier la durée
      if ( $('td',tr).eq(5).text().length > 1 ) {
        $('#duree',form).prop('disabled',true);
        form.append('<p>Cette colle a déjà été relevée&nbsp;: il est impossible de modifier sa durée.</p>');
      }
        
      // Cas des colles classiques : semaine spécifiée
      if ( el.data('sid') ) { 
        $('#description',form).parent().remove();
        // Initialisation du jour (limites)
        var sid = el.data('sid');
        init_jour(sid);
        // Affichage des notes déjà mises. On ne peut modifier que ces notes-là
        // Ces données peuvent être des entiers si un seul élève -> on utilise toString
        var eleves = el.data('eleves').toString().split('|');
        var notes = el.data('notes').toString().split('|');
        eleves.forEach( function(eleve,i) { $('tr[data-id="'+eleve+'"]',form).addClass('orig').show(0).find('select').val(notes[i]); });
        $('tr:not(.orig), .orig option[value="x"]',table).remove();
      }
      // Pas de note d'élève donc séance de TD : on supprime le tableau et on récupère la description
      else {
        table.remove();
        $('h3',form).text('Modifier une séance de TD sans note');
        $('#jour',form).prev().text('Jour :');
        $('#rattrapage',form).parent().remove();
        $('#description',form).val($('td',tr).eq(3).text());
      }
    }
  }
}

// Facilités spécifiques d'ajout des événements
$.fn.init_evenements = function() {
  var form = $('#epingle form');
  $('textarea',form).attr('id','texte');
  
  // Gestion des sélections de date/heure
  $('#debut').datetimepicker({
    onShow: function()  {
      this.setOptions({maxDate: $('#fin').val() || false });
    },
    onClose: function(t,input) {
      $('#fin').val(function(i,v){ return v || input.val(); });
      $('#recur_fin').val(function(i,v){ 
        var debut = input.val().substring(0,10);
        var val = v || debut;
        return ( debut.substr(8,2)+debut.substr(3,2)+debut.substr(0,2) > val.substr(8,2)+val.substr(3,2)+val.substr(0,2) ) ? debut : val ;
      });
    }
  });
  $('#fin').datetimepicker({
    onShow: function()  {
      this.setOptions({minDate: $('#debut').val() || false });
    },
    onClose: function(t,input) {
      $('#debut').val(function(i,v){ return v || input.val(); });
    }
  });
  // Case "dates seulement" : changement de format, conservation de l'heure
  $('#jours').on('change',function() {
    var v;
    if ( this.checked )  {
      $('#debut,#fin').each(function() {
        v = this.value.split(' ');
        $(this).val(v[0]).attr('data-heure',v[1]).datetimepicker({ format: 'd/m/Y', timepicker: false });
      });
    }
    else  {
      $('#debut,#fin').each(function() {
        if ( this.hasAttribute('data-heure') )
          $(this).val(this.value+' '+$(this).attr('data-heure')).removeAttr('data-heure');
        $(this).datetimepicker({ format: 'd/m/Y Ghi', timepicker: true });
      });
    }
  });
  // Récurrence des événements
  $('#recur_fin', form).datetimepicker({ format: 'd/m/Y', timepicker: false, onShow: function() { this.setOptions({minDate: $('#debut').val() || false }); } });
  $('#recur', form).on("click change",function() { 
    $('#recur_step, #recur_fin', form).parent().toggle(this.checked);
  }).change();
}

// Facilités spécifiques du formulaire d'ajout d'utilisateurs
$.fn.init_ajout_utilisateurs = function() {
  var f = $('#epingle form');
  $('.affichesiinvite,.affichesiinvitation,.affichesimotdepasse', f).hide(0);
  $('#admin,#matieres,#saisie,#ordre', f).parent().hide(0);
  $('textarea', f).prop('disabled',true).attr('placeholder','Zone de saisie des utilisateurs\nSélectionnez d\'abord un type d\'utilisateur');
  // Gestion des apparitions sélectives des paragraphes
  $('#autorisation,#saisie').on("change",function() {
    var inv = ( $('#autorisation', f).val() == 1 );
    var mdp = ( $('#saisie', f).val() == 2 );
    $('#saisie,#ordre', f).parent().toggle(!inv);
    $('.affichesiinvite', f).toggle(inv);
    $('.affichesinoninvite', f).toggle(!inv);
    $('.affichesiinvitation', f).toggle(!inv && !mdp);
    $('.affichesiinvitation.eleves', f).toggle(!inv && !mdp && ( $('#autorisation', f).val() == 2 ) );
    $('.affichesimotdepasse', f).toggle(!inv && mdp);
    $('#admin', f).parent().toggle( $('#autorisation', f).val() > 2 );
    $('textarea', f).prop('disabled',false).attr('placeholder', function() { return maj_textarea(f, inv); });
  });
  $('#ordre', f).on("change",function() {
    $('.ordre').text( $('.ordre' + $('#ordre', f).val(), f).show(0).text() + ',' + $('.ordre' + (3-$('#ordre', f).val()), f).hide(0).text() );
    $('textarea', f).attr('placeholder', function() { return maj_textarea(f, $('#autorisation', f).val() == 1 ); });
  });
  $('.ordre2,.affichesinoninvite', f).hide(0);
  var maj_textarea = function(f, inv) {
    if ( inv ) 
      return 'identifiant_1,motdepasse_1\nidentifiant_2,motdepasse_2\nidentifiant_3,motdepasse_3\n...';
    else {
      var ligne = $('.annonce:visible', f).text().substring(9);
      return ligne.replace(/,/g,'_1,') + '_1\n' + ligne.replace(/,/g,'_2,') + '_2\n' + ligne.replace(/,/g,'_3,') + '_3\n...';
    }
  }
}

// Facilités du formulaire de modification des transferts de documents
$.fn.init_transferts = function() {
  var parent = $(this).parent();
  var form = $('#epingle form').append('<input type="hidden" name="matiere" value="'+$('body').data('matiere')+'">');
  // La gestion de la date de disponibilité est faite dans formulaire() 
  $('#deadline', form).val( parent.data('deadline') || '' ).datetimepicker({ format: 'd/m/Y Ghi', timepicker: true, onShow: function() { this.setOptions({minDate: new Date()}); } });
  $('#echeance', form).prop("checked",!!parent.data('deadline')).on("click change",function() { $('#deadline', form).parent().toggle(this.checked); }).change();
  // Initialisation si modification
  if ( parent.data('id') ) {
    $('#titre',form).val(parent.children('h3').text());
    $('#prefixe',form).val(parent.data('prefixe'));
    $('textarea', form).val($('.indications',parent).supprimeMathJax().html() || '');
  }
}

// Facilités du formulaire de visualisation des documents d'un transfert
$.fn.init_transdocs = function(action) {
  var form = $('#epingle form').data('ordre','alphaasc');
  var tid = form.find('input[name="id"]').val();
  var nom = $('article[data-id=' + $(this).parent().data('id') + '] h3').text();
  $('h3.edition',form).html( ( action == 'ajout-transdocs' ) ? 'Envoyer des documents - <em>'+nom+'</em>' : 'Détails du transfert <em>'+nom+'</em>' );
  $('a.icon-actualise', form).insertBefore(form).on("click",majtableau);
  // Icônes d'ordre
  $('p.icones',form).children().insertBefore(form).on("click",function() {
    $(this).addClass('actuel').siblings().removeClass('actuel');
    form.data('ordre',this.className.slice(5,-7));
    $('#epingle a.icon-actualise').click();
  });
  $('p.icones',form).remove();
  $('em.prefixe',form).text($('article[data-id='+tid+']').data('prefixe'));
  // Pas de validation pour le formulaire où on regarde la liste
  if ( action == 'voir-transdocs' )
    $('#epingle .icon-ok').hide(0);
  
  // Fonction de remplissage du tableau
  function majtableau() {
    // On marque les lignes à supprimer sans le faire de suite
    $('tr', form).slice(1).addClass('a_supprimer');
    $('#liste', form).addClass('a_supprimer');
    $.ajax({url: 'recup.php',
            method: "post",
            data: { action:'transdocs', id: tid, ordre: form.data('ordre') },
            dataType: 'json',
            attente: 'Récupération de la liste des documents',
            afficheform: function(data) {
              // On laisse uniquement une ligne pour conserver le layout
              $('tr.a_supprimer').remove();
              // Récupération des valeurs et écriture 
              var lignes = data['lignes'];
              var table = $('tbody', form);
              if ( lignes.length )  {
                // Liste des élèves concernés
                var presents = [];
                lignes.forEach(function(ligne) {
                  if ( ligne.length == 3 )
                    table.append('<tr data-id="'+ligne[0]+'"><td>'+ligne[2]+'</td><td colspan="5"><em>Document envoyé par un autre utilisateur</em></td></tr>');
                  else
                    table.append('<tr data-id="'+ligne[0]+'" data-verif="'+ligne[6]+'"><td>'+ligne[2]+'</td><td>'+ligne[3]+'</td><td>'+ligne[4]+'</td><td>'+ligne[5]+'</td>\
                                  <td class="icones"><a class="icon-download" title="Télécharger ce document"></a> <a class="icon-supprime" title="Supprimer ce document"></a></td>\
                                  <td class="icones"><input type="checkbox"></td></tr>');
                  presents.push(parseInt(ligne[1]));
                });
                // Cochage
                $('tr',form).find('td:not(:nth-last-child(1), :nth-last-child(2))').on("click",function() {
                  $(this).parent().find('input').click().change();
                });
                $('input:checkbox',form).on("change",function() {
                  $(this).parent().parent().toggleClass('sel',this.checked);
                  $('th.icones a',form).not('.icon-cocher').toggleClass('noact',!$('input:checked',form).length);
                });
                $('th.icones a',form).not('.icon-cocher').addClass('noact');
                // Icônes de cochage multiple
                $('.icon-cocher',form).on("click", function() {
                  $(this).toggleClass('icon-cocher icon-decocher');
                  $('input:checkbox',form).prop('checked', $(this).hasClass('icon-decocher')).change();
                });
                // Affichage des absents du tableau
                var absents = enoms.reduce(function(total,nom,i){ 
                  if ( presents.indexOf(eids[i]) == -1 )
                    return total+', '+nom.split(',').reverse().join(' ');
                  return total;
                },'').substr(2);
                var absents_ids = eids.reduce(function(total,id,i){ 
                  if ( presents.indexOf(eids[i]) == -1 )
                    return total+','+id;
                  return total;
                },'').substr(1);
                table.parent().before( ( absents ) ? '<p id="liste"><strong><a class="icon-mail" href="mail?enr_dests&uids='+absents_ids+'" title="Leur envoyer un mail"></a>&nbsp;Élèves absents du tableau&nbsp;:</strong> '+absents+'.</p>' : '<p id="liste">Tous les élèves sont présents dans ce tableau.</p>');
              }
              else 
                table.append('<tr><td class="centre" colspan="6">Aucun résultat trouvé</td></tr>');
              // On supprime l'éventuelle ligne laissée et le paragraphe d'indications
              $('.a_supprimer').remove();
              // Bouton de téléchargement individuel
              $('td a.icon-download', table).on("click",function() {
                // Test de connexion : on fait le téléchargement en get, donc on doit
                // être connecté en connection non light avant
                $.ajax({url: 'ajax.php',
                        method: "post",
                        data: 'verifconnexion=1',
                        dataType: 'json',
                        el: $(this).parent().parent(),
                        fonction: function(el) {
                          $('#log').hide(0);
                          window.location.href = 'transferts.php?dl=' + el.data('id') + '&t=' + tid + '&verif=' + el.data('verif');
                        }
                });
              });
              // Suppression individuelle 
              $('td a.icon-supprime', table).on("click",function() {
                var ligne = $(this).parent().parent();
                // Demande de confirmation
                confirmation('Vous allez supprimer un document. Cette action n\'est pas annulable.', ligne, function(ligne) {
                  $.ajax({url: 'ajax.php',
                          method: "post",
                          data: { action:'suppr-transdocs', id:ligne.data('id'), transfert: tid },
                          dataType: 'json',
                          el: ligne,
                          fonction: function(el) {
                            el.remove();
                          }
                  });
                });
              });
            }
    });
  }
  majtableau();
  
  // Téléchargement multiple
  $('th .icon-download', form).on("click",function() {
    var cases = $('input:checked',form);
    if ( cases.length == 0 ) {
      affiche('<p>Aucune case n\'est cochée, aucune action ne peut être réalisée.</p>','nok');
      return
    }
    // On positionne un marqueur d'utilisation de fonction asynchrone pour 
    // modifier le comportement de ajaxStop.
    $('body').data('async',true);
    // Test de connexion : on fait le téléchargement en get, donc on doit
    // être connecté en connection non light avant
    $.ajax({url: 'ajax.php',
            method: "post",
            data: 'verifconnexion=1',
            dataType: 'json',
            el: '',
            fonction: async function(el) {
              $('#log').hide(0);
              $('#load').html('<p>Transfert en cours<span></span></p><img src="js/ajax-loader.gif">');
              // Données globales
              var lignes = cases.parent().parent();
              var ids = lignes.map(function() { return $(this).data('id'); }).get();
              var verifs = lignes.map(function() { return $(this).data('verif'); }).get();
              var fichiers = [];
              var recu = 0;
              var total = lignes.map(function() { return eval('('+$(this).find('td:eq(2)').text().replace('Mo','+0.5)*1024*1024').replace('ko','+0.5)*1024')); }).get().reduce((a,b)=>a+b);
              // Téléchargement des fichiers avec suivi de la quantité téléchargée
              try {
                var bg = $('#load p').css('background');
                var pourcent = 0;
                for ( var i = 0 ; i < ids.length ; i++ ) {
                  fichiers[i] = await fetch('transferts?zip&dl='+ids[i]+'&t='+tid+'&verif=' + verifs[i]).then(async function(response) {
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
                link.download = $('article[data-id='+tid+']').data('prefixe') + '.zip';
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
    });
  });
  // Suppression multiple
  $('th .icon-supprime', form).on("click",function() {
    var cases = $('input:checked', form);
    if ( cases.length == 0 ) {
      affiche('<p>Aucune case n\'est cochée, aucune action ne peut être réalisée.</p>','nok');
      return
    }
    var lignes = cases.parent().parent();
    var ids = lignes.map(function() { return $(this).data('id'); }).get().join(',');
    confirmation('Vous allez supprimer '+cases.length+' documents. Cette opération n\'est pas annulable.', this, function(el) {
      $.ajax({url: 'ajax.php',
              method: "post",
              data: { action:'suppr-transdocs', ids: ids, transfert: id },
              dataType: 'json',
              el: '',
              fonction: function() {
                $('#epingle a.icon-actualise').click();
              }
      });
    });
  });
  
  // Formulaire d'ajout de documents
  // Reprise de init_docs
  if ( action == 'ajout-transdocs' )  {
    form.addClass('formdoc');
    // Fabrication du select des élèves
    var sel_eleves = $('<select multiple></select>');
    enoms.forEach( function(e,i) {
      sel_eleves.append('<option value="'+eids[i]+'">'+e.replace(',',' ')+'</option>');
    });
    $('input[type="file"]', form).attr('id','fichier').on('change',function() {
      var fichiers = this;
      $('select[id^="eleve"]', form).parent().remove();
      for (var i = 0, n = fichiers.files.length, nom = '', ok = false; i < n; i++) {
        nom = fichiers.files[i].name;
        $('<p class="ligne"><label for="eleve'+i+'">'+nom+'&nbsp;: </label></p>').append( sel_eleves.clone().attr('id','eleve'+i).attr('name','eid'+i+'[]') ).insertAfter( $('.ligne',form).last() );
        // Recherche de correspondance : d'abord nom et prénom, utile en cas de jumeaux
        ok = false;
        nom = ( nom.substring(nom.lastIndexOf('\\')+1,nom.lastIndexOf('.')) || nom ).toLowerCase();
        enoms.forEach( function(e,j) { 
          if ( !ok && nom.indexOf(e.replace(',',' ').toLowerCase()) > -1 )  { 
            $('#eleve'+i).val(eids[j]); 
            ok = true; 
          }
        });
        enoms.forEach( function(e,j) { 
          if ( !ok && nom.indexOf(e.split(',')[0].toLowerCase()) > -1 )  { 
            $('#eleve'+i).val(eids[j]); 
            ok = true; 
          }
        });
      }
      $('select',form).init_selmult().on("change",function() { $(this).prev().toggleClass('nok',this.value.length == 0); }).change();
      $('select',form).entreevalide(form);
    });
    
    // Envoi du fichier -- redéfinit le comportement par défaut défini par formulaire()
    $('#epingle a.icon-ok').removeClass('icon-ok').addClass('icon-envoidoc').on("click",function() {
      // Vérification que chaque document est associé à un élève
      if ( $('select.nok',form).length )  {
        affiche('Certains documents n\'ont pas d\'élève associé.','nok');
        return;
      }
      // Test de connexion
      // Si reconnect() appelée, le paramètre connexion sert à obtenir un retour
      // en état ok/nok pour affichage si nok. Si ok, on réécrit le message. 
      $.ajax({url: 'ajax.php',
              method: "post",
              data: 'verifconnexion=1',
              dataType: 'json',
              el: '',
              fonction: function(el) {
                // Si transfert, pas d'affichage dans le div de log
                $('#log').hide(0);
                // Envoi réel du fichier ou des données
                var data = new FormData(form[0]);
                // Envoi
                $.ajax({url: 'ajax.php',
                        xhr: function() { 
                          // Évolution du transfert si fichier transféré
                          var xhr = $.ajaxSettings.xhr();
                          if ( xhr.upload && ( $('#fichier')[0].files.length > 0 ) ) {
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
                        processData:false,
                        fonction: function() {
                          $('select',form).parent().remove();
                          $('#fichier').val('');
                          majtableau();
                        }
                });
              }
      });
    });
  }
}

// Facilités du formulaire de modification pour l'ajout d'une page
// Sert uniquement à mettre à jour la matière lorsqu'elle change, pour les
// deux select de protection et d'édition
$.fn.init_page = function() {
  // Valeurs par défauts utilisées par selmult (qui n'est pas encore lancé)
  $('#epingle').data('matiere',0);
  $('body').data('protection',0);
  $('body').data('edition',0);
  var form = $('#epingle form');
  $('#matiere', form).on("change",function() {
    $('#epingle').data('matiere',parseInt(this.value));
    $('#protection', form).next().attr('name','protection[]').parent().data('protection',$('#protection', form).val());
    $('#edition', form).next().attr('name','edition[]').parent().data('edition',$('#edition', form).val());
    $('#protection,#edition',form).remove();
    $('select[multiple]', form).init_selmult();
  });
}

/////////////////////////////
// Transferts de documents //
/////////////////////////////

// Liens de téléchargement d'un transfert complet, présents sur transferts.php
// Il faut récupérer le préfixe, la liste des identifiants téléchargeables
// et les codes de vérifications donnés par recup.php pour chaque document
// accessible du transfert. 
function download_transfert() {
  var article = $(this).parent().parent();
  var tid = article.data('id');
  var prefixe = article.data('prefixe');
  // On positionne un marqueur d'utilisation de fonction asynchrone pour 
  // modifier le comportement de ajaxStop.
  $('body').data('async',true);
  affiche('Récupération de la liste des documents','ok');
  $.ajax({url: 'recup.php',
          method: "post",
          data: { action:'transdocs', id: tid },
          dataType: 'json',
          afficheform: async function(data) {
            // On n'affiche rien en réalité ici
            var lignes = data['lignes'];
            if ( lignes )  {
              $('#log').hide(0);
              $('#load').html('<p>Transfert en cours<span></span></p><img src="js/ajax-loader.gif">').show(0);
              // Liste des documents : on ne garde que les lignes de plus
              // de 3 valeurs, avec le code de vérification
              var ids = [];
              var verifs = [];
              var fichiers = [];
              var recu = 0;
              var total = 0;
              lignes.forEach(function(ligne) {
                if ( ligne.length > 3 )  {
                  ids.push(ligne[0]);
                  verifs.push(ligne[6])
                  total += eval('('+ligne[4].replace('&nbsp;','').replace('Mo','+0.5)*1024*1024').replace('ko','+0.5)*1024'));
                }
              });
              // Téléchargement des fichiers avec suivi de la quantité téléchargée
              try {
                var bg = $('#load p').css('background');
                var pourcent = 0;
                for ( var i = 0 ; i < ids.length ; i++ ) {
                  fichiers[i] = await fetch('transferts?zip&dl='+ids[i]+'&t='+tid+'&verif=' + verifs[i]).then(async function(response) {
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
                    return {name: decodeURIComponent(response.headers.get('Content-Disposition').split('="')[1]).slice(0,-1), input: new Blob(chunks)}
                  });
                }
                $('#load span').html(' - 100%');
                $('#load p').css('background',bg.replace(/0%/g,'100%'));
                // Construction de l'url blob permettant de simuler le zip à récupérer
                var blob = await downloadZip(fichiers).blob();
                var link = document.createElement("a");
                link.href = URL.createObjectURL(blob);
                link.download = $('article[data-id='+tid+']').data('prefixe') + '.zip';
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
}

///////////////////////////////////////////////////////
// Modifications des utilisateurs, matières, groupes //
///////////////////////////////////////////////////////

// Recherche d'utilisateur depuis la topbarre
function recherche_utilisateurs() {
  var t = $('table.utilisateurs');
  if (this.value.length >= 2) {
    $('tr:not(.categorie):not(:has(th)):not(:icontains("'+this.value+'"))', t).hide(0);
    $('tr:not(.categorie):not(:has(th)):icontains("'+this.value+'")', t).show(0);
    $('.icon-cocher', t).hide(0);
  }
  else {
    $('tr:not(.categorie):not(:has(th))', t).filter(function() { return $(".cache",this).length; }).hide(0);
    $('tr:not(.categorie):not(:has(th))', t).filter(function() { return !$(".cache",this).length; }).show(0);
    $('.icon-cocher', t).show(0);
  }
}

// Cochage/Décochage multiple des utilisateurs
function cocher_utilisateurs() {
  // Cochage automatique
  $(this).toggleClass('icon-cocher icon-decocher').parent().parent().nextUntil('.categorie').find('input').prop('checked',$(this).hasClass('icon-decocher')).change();
  // Activation/désactivation des icônes d'action
  $(this).parent().prev().find('a').toggleClass('noact',$(this).hasClass('icon-cocher'));
  // Cas particulier d'une entrée de valeur nulle : choix exclusif non sélectionnable par le cochage multiple
  $('input[value=0]').prop('checked',false).change();
}

// Édition d'un compte utilisateur (utilisateurs.php et utilisateurs-mails.php)
function edite_utilisateur() {
  var id = $(this).parent().parent().data('id');
  // Récupération des données associées au compte
  $.ajax({url: 'recup.php',
          method: "post",
          data: { action:'prefs', id:id },
          dataType: 'json',
          attente: 'Récupération des données',
          afficheform: function(data) {
            if ( 'nom' in data ) {
              popup($('#form-edite').html(),true);
              var f = $('#fenetre');
              // Création de l'identifiant des champs à partir du name
              $('input,select',f).attr('id',function(){ return this.getAttribute('name'); });
              // Suppression des paragraphes et des questions non valables
              if ( data['valide'] )             $('#comptedesactive, #demande, #invitation', f).remove();
              else if ( data['demande'] )       $('#compteactif, #comptedesactive, #invitation', f).remove();
              else if ( data['invitation'] )    $('#compteactif, #comptedesactive, #demande', f).remove();
              else                              $('#compteactif, #demande, #invitation', f).remove();
              if ( data['autorisation'] == 1 ) {
                $('#nom, #prenom, #mail1, #mail2', f).parent().remove();
                $('hr', f).nextAll().addBack().remove();
              }
              $('.admin'+(1-data['admin']),f).remove();
              // Personalisation du premier paragraphe
              $('p:first', f).html(function(i,code){ return code.replace('XXX', data['prenom'].length ? 'de <em>'+data['prenom']+' '+data['nom']+'</em>' : '<em>'+data['login']+'</em>')
                                                                .replace('YYY','<em>'+['Invité','Élève','Colleur','Lycée','Professeur'][data['autorisation']-1]+'</em>'); });
              // Peuplement du formulaire
              $('input[type="text"],input[type="email"],select',f).val(function(){ return data[this.id]; });
              $('input[type="checkbox"]',f).prop("checked",function(){ return data[this.id]; });
              if ( !data['mailenvoi'] )
                $('#mailcopie', f).parent().remove();
              $('#autorisation', f).on("change",function() {
                $('#admin', f).prop('disabled',this.value <= 2).parent().toggle(this.value > 2);
              }).change();
              // Bloquage après modification et envoi par appui sur Entrée
              $('input,select',f).bloque().entreevalide(f);
              // Envoi par clic sur l'icône
              $('a.icon-ok', f).on("click",function() {
                $.ajax({url: 'ajax.php',
                        method: "post",
                        data: 'action=utilisateur&modif=prefs&id='+id+'&'+$('form', f).serialize(),
                        dataType: 'json',
                        el: false,
                        fonction: Function.prototype
                });
              });
            }
          }
        });
}

// Initialisation du tableau des utilisateurs
function init_utilisateurs() {
  // Case à cocher pour autoriser ou non les demandes de comptes
  $('#creation_compte').on("click",function() {
    $.ajax({url: 'ajax.php',
        method: "post",
        data: 'action=prefsglobales&id=creation_compte&val='+(this.checked|0),
        dataType: 'json',
        el: false,
        fonction: Function.prototype
    });
  });
  
  // Ordre du tableau par rechargement
  $('.ordre_nom').on("click", function() { window.location = '?type='+$('#type').val()+'&amp;matiere='+$('#matiere').val()+'&amp;ordre=nom'; });
  $('.ordre_type').on("click", function() { window.location = '?type='+$('#type').val()+'&amp;matiere='+$('#matiere').val()+'&amp;ordre=type'; });
  
  // Désactivation des icônes de catégories
  $('th.icones a').not('.icon-cocher').addClass('noact');
  
  // Icônes de cochage multiple
  $('.icon-cocher').on("click", cocher_utilisateurs);

  // Édition de comptes uniques
  $('td .icon-edite').on("click", edite_utilisateur);

  // Désactivation, réactivation, suppression de comptes uniques et validation de demandes
  $('td .icon-desactive, td .icon-active, td .icon-supprutilisateur, td .icon-validutilisateur, td .icon-renvoiinvite').on("click", modif_utilisateur);

  // Édition, désactivation, réactivation, suppression de comptes multiples et validation de demandes
  $('th .icon-desactive, th .icon-active, th .icon-supprutilisateur, th .icon-validutilisateur, th .icon-renvoiinvite').on("click", modif_utilisateurs);

  // Clic sur toute la ligne (premières cases seulement)
  $('td:not(.icones)').on("click",function() { $(this).parent().find('input').click(); });

  // Clic sur une case à cocher unique
  $('#u input').on("change",function() {
    // Mise en évidence de la ligne
    $(this).parent().parent().toggleClass('sel',this.checked);
    // Activation/désactivation des icônes d'action
    var ligneactions = $(this).parent().parent().prevUntil('.categorie').last();
    ligneactions.find('a').not('.icon-cocher').toggleClass('noact',!ligneactions.nextUntil('.categorie').find(':checked').length);
  });
  
  /////////////
  // Fonctions

  // Désactivation, réactivation, suppression, validation d'un compte utilisateur
  function modif_utilisateur() {
    var question = '';
    var nom = $(this).parent().siblings().first();
    var compte = ( nom.text().length ) ? 'de <em>'+nom.next().text()+' '+nom.text()+'</em>' : 'd\'identifiant <em>'+nom.next().next().text()+'</em>';
    var categorie = $(this).parent().parent().prevUntil('.categorie').last().prev().text();
    categorie = ( categorie.split(' ')[1] == 'actuellement' ) ? $(this).parent().prev().text().split(' ')[0] : categorie.split(' ')[0];
    switch ( this.className.substring(5) ) {
      case 'desactive':
        if ( categorie == 'Invité' )
          question = 'Vous allez désactiver le compte invité '+compte+'. Cela signifie que le compte ne sera pas supprimé mais sera non utilisable pour une connexion. Les associations éventuelles avec les matières seront conservées. Ce compte sera listé dans la partie inférieure du tableau.';
        else 
          question = 'Vous allez désactiver le compte '+compte+'. Cela signifie que le compte sera toujours visible pour les administrateurs mais que l\'utilisateur correspondant ne pourra plus se connecter. <strong>Les notes de colles éventuelles seront conservées. Les données associées au compte seront conservées.</strong><br> Les accès spécifiques éventuels pourront être rétablis en réactivant le compte.<br> Ce compte sera listé dans la partie inférieure du tableau.<br> Cette possibilité est particulièrement utile pour un élève ou un colleur parti en cours d\'année et dont il faut conserver les notes de colles.';
        break;
      case 'active':
        if ( categorie == 'Invité' )
          question = 'Vous allez réactiver le compte invité '+compte+'. La connexion sera à nouveau possible. Ce compte apparaîtra à nouveau dans la partie principale du tableau.';
        else
          question = 'Vous allez réactiver le compte '+compte+'. Cela signifie que l\'utilisateur correspondant pourra à nouveau se connecter. Il retrouvera son compte, ses notes de colles éventuelles, ses préférences, ses accès spécifiques éventuels, sans modification. Ce compte apparaîtra à nouveau dans la partie principale du tableau.';
        break;
      case 'supprutilisateur':
        if ( categorie == 'Demandes' )
          question = 'Vous allez supprimer la demande '+compte+'. Cela signifie que cette demande ne conduira pas à une création de compte. Le demandeur ne sera pas prévenu de votre décision.<br> Une fois réalisée, cette opération est définitive, mais rien n\'empêche le demandeur d\'effectuer une nouvelle demande.<br> <strong>Si vous n\'attendez plus de nouvelle demande de création de compte, il est certainement préférable de supprimer cette possibilité en décochant la case ci-dessus.</strong>';
        else if ( categorie == 'Invitations' ) {
          var textecolles = ( $(this).parent().prev().prev().text() == 'Élève' ) ? '<p class="note"><strong>ATTENTION : toutes les notes de colles qui ont déjà pu être déclarées sur ce compte seront supprimées. Cette suppression est définitive.</strong></p><p>' : '<br>';
          question = 'Vous allez supprimer l\'invitation '+compte+'. Cela signifie que cette invitation ne sera plus valable et que si la personne invitée clique sur le lien reçu par courriel, une erreur apparaîtra devant elle.'+textecolles+'<strong>L\'invitation envoyée n\'a pas de date de péremption&nbsp;: il est n\'est pas normal de supprimer l\'invitation pour la refaire, à moins de s\'être trompé d\'adresse électronique.</strong><br>Si la personne invitée vous dit avoir perdu l\'invitation, vous pouvez la lui renvoyer en cliquant sur <span class="icon-actualise"></span>.<br>Si elle ne l\'a jamais reçue, vérifiez bien avec elle l\'adresse électronique que vous avez saisie avant de la supprimer.<br> La personne invitée ne sera pas prévenue de votre décision.';
        }
        else if ( categorie == 'Professeur' )
          question = 'Vous allez supprimer le compte professeur '+compte+'. <strong>Cela signifie que toutes les préférences de ce compte seront perdues, ainsi que les éventuelles notes de colles.</strong> <p class="note"><strong>Supprimer un compte pour le recréer n\'est pas la bonne méthode pour réinitialiser un compte,</strong></p><p> par exemple si l\'utilisateur du compte vous indique ne pas arriver à se connecter. Dans ce cas, proposez-lui de passer par le lien <em>Mot de passe oublié</em>. Vous pouvez modifier sur la <a href="utilisateurs">gestion des utilisateurs</a> les nom, prénom, identifiant de connexion et adresse électronique de chaque utilisateur.<br> Pour conserver les données de l\'utilisateur mais lui empêcher la connexion, vous pouvez désactiver le compte en cliquant sur <span class="icon-desactive"></span>.<br> Les données des matières auxquelles il est associé sont indépendantes&nbsp;: elles ne seront pas supprimées.';
        else if ( categorie == 'Lycée' )
          question ='Vous allez supprimer le compte lycée '+compte+'. Cela signifie que toutes les préférences de ce compte seront perdues.';
        else if ( categorie == 'Colleur' )
          question = 'Vous allez supprimer le compte colleur '+compte+'. <strong>Cela signifie que toutes les préférences de ce compte seront perdues, ainsi que les éventuelles notes de colles.</strong> <p class="note"><strong>Supprimer un compte pour le recréer n\'est pas la bonne méthode pour réinitialiser un compte,</strong></p><p> par exemple si l\'utilisateur du compte vous indique ne pas arriver à se connecter. Dans ce cas, proposez-lui de passer par le lien <em>Mot de passe oublié</em>. Vous pouvez modifier sur la <a href="utilisateurs">gestion des utilisateurs</a> les nom, prénom, identifiant de connexion et adresse électronique de chaque utilisateur.<br> Pour conserver les données de l\'utilisateur mais lui empêcher la connexion, vous pouvez désactiver le compte en cliquant sur <span class="icon-desactive"></span>.';
        else if ( categorie == 'Élève' )
          question = 'Vous allez supprimer le compte élève '+compte+'. <strong>Cela signifie que toutes les données correspondant à ce compte seront perdues. Les groupes où il apparaît seront modifiés, les notes de colles éventuelles seront supprimées.</strong> <p class="note"><strong>ATTENTION : toutes les notes de colles qui ont déjà pu être déclarées sur ce compte seront supprimées. Cette suppression est définitive.<br> Supprimer un compte pour le recréer n\'est pas la bonne méthode pour réinitialiser un compte,</strong></p><p> par exemple si l\'utilisateur du compte vous indique ne pas arriver à se connecter. Dans ce cas, proposez-lui de passer par le lien <em>Mot de passe oublié</em>. Vous pouvez modifier sur la <a href="utilisateurs">gestion des utilisateurs</a> les nom, prénom, identifiant de connexion et adresse électronique de chaque utilisateur.<br> Pour conserver les données de l\'utilisateur mais lui empêcher la connexion, vous pouvez désactiver le compte en cliquant sur <span class="icon-desactive"></span>.';
        else if ( categorie == 'Invité' )
          question ='Vous allez supprimer le compte invité '+compte+'. Cela signifie que la connexion par ce compte ne sera plus possible.';
        else
          question = 'Vous allez supprimer le compte '+compte+' déjà désactivé. <strong>Cela signifie que toutes les données correspondant à ce compte seront perdues définitivement. Les groupes où il apparaît seront modifiés, les notes de colles éventuelles seront supprimées.</strong>';
        if ( categorie != 'Demandes' )
          question = question + '<br>Une fois réalisée, cette opération est définitive.';
        break;
      case 'validutilisateur':
        question = 'Vous allez valider la demande '+compte+'. Son compte sera immédiatement actif et un courriel va immédiatement être envoyé pour le/la prévenir.<br> Il sera automatiquement associé à toutes les matières&nbsp;: <strong>pensez à aller supprimer les matières qui ne le concernent pas sur la page de gestion des associations utilisateurs-matières.</strong>';
        break;
      case 'renvoiinvite':
        question = 'Vous allez renvoyer un courriel d\'invitation à '+compte.substring(3)+'. Ce courriel devrait être reçu immédiatement. Ne réalisez cette action que si la personne concernée est sûre de ne pas avoir le courriel déjà envoyé une première fois. Si plusieurs envois ne changent rien, <strong>pensez à vérifier l\'adresse électronique</strong>.<p class="annonce">Il est fréquent qu\'une adresse recopiée depuis une feuille manuscrite soit fausse, merci de faire attention à ne pas envoyer des courriels à des adresses inexistantes.</p> Les grands gestionnaires de courriels se servent de cet indicateur pour dépister les spammeurs et ce site pourrait être pris comme tel. Vous pouvez demander à l\'administrateur si des retours de courriels non délivrés ont été observés sur cette adresse.';
    }
    confirmation(question, this, function(el) {
      $.ajax({url: 'ajax.php',
              method: "post",
              data: { action:'utilisateur', modif:el.className.substring(5), id:$(el).parent().parent().data('id') },
              dataType: 'json'
      });
    });
  }
  
  // Désactivation, réactivation, suppression, validation multiple de comptes utilisateurs
  function modif_utilisateurs() {
    var cases = $(this).parent().parent().nextUntil('.categorie').find(':checked');
    if ( cases.length == 0 ) {
      affiche('<p>Aucune case n\'est cochée, aucune action ne peut être réalisée.</p>','nok');
      return
    }
    var lignes = cases.parent().parent();
    var ids = lignes.map(function() { return $(this).data('id'); }).get().join(',');
    var comptes = lignes.map(function() {
      var nom = $(this).children().first().text();
      return ( nom.length ) ? '<em>'+$(this).children().eq(1).text()+' '+nom+'</em>' : '<em>'+$(this).children().eq(2).text()+'</em>';
    }).get().join(', ');
    var pos = comptes.lastIndexOf(',');
    if ( pos > 0 )
      comptes = comptes.substring(0,pos)+' et'+comptes.substring(pos+1);
    var question = '';
    var categorie = $(this).parent().parent().prev().children().text().split(' ')[0]
    switch ( this.className.substring(5) ) {
      case 'desactive':
        question = 'Vous allez désactiver les comptes de '+comptes+'. Cela signifie que ces comptes seront toujours visibles pour les administrateurs mais que les utilisateurs correspondant ne pourront plus se connecter. <strong>Les notes de colles éventuelles seront conservées. Les données associées aux comptes seront conservées.</strong><br> Les accès spécifiques éventuels pourront être rétablis en réactivant les comptes.<br> Ces comptes seront listés dans la partie inférieure du tableau.<br> Cette possibilité est particulièrement utile pour des élèves ou des colleurs partis en cours d\'année et dont il faut conserver les notes de colles.';
        break;
      case 'active':
        question = 'Vous allez réactiver les comptes de '+comptes+'. Cela signifie que les utilisateurs correspondant pourront à nouveau se connecter. Ils retrouveront leur compte, leurs notes de colles éventuelles, leurs préférences, leurs accès spécifiques éventuels, sans modification. Ces comptes apparaîtront à nouveau dans la partie principale du tableau.';
        break;
      case 'supprutilisateur':
        if ( categorie == 'Demandes' )
          question = 'Vous allez supprimer les demandes de '+comptes+'. Cela signifie que ces demandes ne conduiront pas à des créations de compte. Les demandeurs ne seront pas prévenus de votre décision.<br> Une fois réalisée, cette opération est définitive, mais rien n\'empêche les demandeurs d\'effectuer une nouvelle demande.<br> <strong>Si vous n\'attendez plus de nouvelle demande de création de compte, il est certainement préférable de supprimer cette possibilité à l\'aide du réglage accessible en cliquant sur l\'icône <span class="icon-prefs"></span> en haut à droite sur cette page</strong>';
        else if ( categorie == 'Invitations' ) {
          question = 'Vous allez supprimer les invitations de '+comptes+'. Cela signifie que ces invitations ne seront plus valables et que si les personnes invitées cliquent sur le lien reçu par courriel, une erreur apparaîtra devant elles. <p class="note"><strong>ATTENTION : toutes les notes de colles qui ont déjà pu être déclarées sur les comptes de types élèves seront supprimées. Ces suppressions sont définitives.</strong></p> <p><strong>Ces invitations envoyées n\'ont pas de date de péremption&nbsp;: il n\'est pas normal de supprimer une invitation pour la refaire, à moins de s\'être trompé d\'adresse électronique. Si une personne invitée vous dit ne pas réussir à s\'identifier, proposez-lui de passer par le lien <em>Mot de passe oublié</em>.</strong><br> Les personnes invitées ne seront pas prévenues de votre décision.<br>Une fois réalisée, cette opération est définitive.';
        }
        else  {
          question = 'Vous allez supprimer les comptes de '+comptes+'. <strong>Cela signifie que toutes les préférences de ces comptes seront perdues, ainsi que les éventuelles notes de colles ou transferts de documents qui leur sont liés.</strong> <p class="note"><strong>Supprimer un compte pour le recréer n\'est pas la bonne méthode pour réinitialiser un compte,</strong></p><p> par exemple si l\'utilisateur d\'un compte vous indique ne pas arriver à se connecter. Dans ce cas, proposez-lui de passer par le lien <em>Mot de passe oublié</em>.<br> Pour conserver les données d\'un utilisateur mais lui empêcher la connexion, vous pouvez désactiver le compte en cliquant sur <span class="icon-desactive"></span>.<br> Les données des matières auxquelles ces utilisateurs sont associés sont indépendantes&nbsp;: elles ne seront pas supprimées.<br>Une fois réalisée, cette opération est définitive.';
          var liste_types = lignes.find('td:eq(3)').text();
          if ( ( liste_types.indexOf('Prof') >= 0 ) && ( liste_types.indexOf('Élève') >= 0 ) )
            question = question + '<br><p class="note"><strong>Vous allez supprimer simultanément des comptes de professeurs et des comptes d\'élèves. Est-ce normal&nbsp;?';
        }
        break;
      case 'validutilisateur':
        question = 'Vous allez valider les demandes de '+comptes+'. Leurs comptes seront immédiatement actifs et un courriel va immédiatement leur être envoyé pour les prévenir.<br> Ils seront automatiquement associés à toutes les matières&nbsp;: <strong>pensez à aller supprimer les matières qui ne les concernent pas sur la page de gestion des associations utilisateurs-matières.</strong>';
        break;
      case 'renvoiinvite':
        question = 'Vous allez renvoyer un courriel d\'invitation à '+comptes+'. Ces courriels devraient être reçus immédiatement. Ne réalisez cette action que si les personnes concernées sont sûres de ne pas avoir le courriel déjà envoyé une première fois. Si plusieurs envois ne changent rien, pensez à vérifier les adresses électroniques. Vous pouvez demander à l\'administrateur si des retours de courriels non délivrés ont été observés sur ces adresses.';
    }
    confirmation(question, this, function(el) {
      $.ajax({url: 'ajax.php',
              method: "post",
              data: { action:'utilisateurs', modif:el.className.substring(5), ids:ids },
              dataType: 'json'
      });
    });
  }
}

// Initialisation du tableau des associations utilisateurs-matières
function init_utilisateurs_matieres() {
  
  // Affichage des icônes d'association
  // La case de tableau HTML contient deux valeurs séparées de "|" :
  // l'identifiant de la matière et une valeur comprise entre 0 et 4 en fonction de l'affichage à réaliser.
  // Explications sur utilisateurs-matieres.php
  $('tr:not(.categorie) td:not(:first-child,:last-child)').each(function() {
    var valeurs = this.textContent.split('|');
    switch ( valeurs[1] ) {
      case '0': this.innerHTML = '<a class="icon-nok" title="Établir l\'association à la matière"></a>'; break;
      case '1': this.innerHTML = '<a class="icon-ok" title="Supprimer l\'association à la matière"></a>'; break;
      case '2': this.innerHTML = '<a class="icon-ok" title="Association à la matière non modifiable"></a>'; break;
      case '3': this.innerHTML = '<a title="Colleur dans cette matière"><strong>C</strong></a>'; break;
      case '4': this.innerHTML = '<a title="Professeur dans cette matière"><strong>P</strong></a>';
    }
    if ( valeurs[1] > 1 )
      this.classList.add('fixe');
    this.childNodes[0].dataset.id = valeurs[0];
  });
  // La table n'est pas affichée avant que la modification du tableau ne soit faite (display:none en html)
  $('#umats').show(0);
  $('#umats a').on("click", association_um);
  $('#umats a').parent().on("mouseenter", function() { 
    var id = this.childNodes[0].dataset.id; 
    $('#m'+id+', a[data-id='+id+'], span[data-id='+id+']').parent().addClass('sel'); 
  })
                        .on("mouseleave", function() { 
    var id = this.childNodes[0].dataset.id; 
    $('#m'+id+', a[data-id='+id+'], span[data-id='+id+']').parent().removeClass('sel'); 
  })
  
  // Icônes de cochage multiple
  $('.categorie [data-id]').on("click", association_ums).hide(0);
  $('.icon-cocher').on("click", cocher_utilisateurs).on("click",majicones);
  $('input[type="checkbox"]').on("click", majicones).on("change",function() {
    // Mise en évidence
    $(this).parent().parent().toggleClass('sel',this.checked);
  });
  
  // Clic sur la case de nom pour cocher
  $('td:first-child').on("click",function() { $(this).parent().find('input').click(); });

  // Clic sur la fonction d'ajout de professeur à la liste des colleurs
  $('#ajoutprof').on("click", function() { 
    $.ajax({url: 'recup.php',
            method: "post",
            data: { action:'listeprofs' },
            dataType: 'json',
            attente: 'Récupération de la liste des professeurs',
            afficheform: function(data) {
              if ( 'ids' in data ) {
                popup($('#form-ajoutprof').html(),true);
                var f = $('#fenetre');
                var ids = data['ids'];
                var noms = data['noms'];
                var matieres = data['matieres'];
                // Construction du tableau "liste des professeurs"
                for ( cle in ids ) 
                  $('table',f).append('<tr><td data-id="'+ids[cle]+'" data-matieres="'+matieres[cle]+'">'+noms[cle]+'</td></tr>');
                // Ajout de la ligne dans le tableau général
                $('td',f).on("click", function() {
                  var ligne = $('#ajoutprof').parent().parent().next().clone(true,true);
                  ligne[0].dataset.id = 'c'+this.dataset.id;
                  var matieres = this.dataset.matieres+',';
                  $('td:first-child',ligne).text(this.textContent+' (Professeur)');
                  $('.icon-ferme',f).click();
                  $('thead span').each(function() {
                    var mid = this.id.substring(1);
                    if ( matieres.indexOf(','+mid+',') > 0 )
                      $('a[data-id='+mid+']',ligne).attr('title','Professeur dans cette matière').html('<strong>P</strong>').removeClass().parent().addClass("fixe");
                    else
                      $('a[data-id='+mid+']',ligne).attr('title','Établir l\'association à la matière en tant que colleur').html('').removeClass().addClass('icon-nok').parent().removeClass('fixe');
                  });
                  $('#ajoutprof').parent().parent().after(ligne);
                });
              }
              else
                popup('<p class="annonce">Il n\'y a aucun professeur à ajouter parmi les colleurs.</p>',true);
            }
    });
  });
  
  // Mise à jour des icônes de modifications multiples
  function majicones() {
    // Ligne de catégorie correspondant à l'icone ou à la case cliquée
    var tr = $(this).parent().parent();
    if ( !tr.hasClass('categorie') )
      tr = tr.prevAll('.categorie').first();
    // Cases cochées
    var cases = tr.nextUntil('.categorie').find(':checked');
    if ( cases.length == 0 )
      $('[data-id]',tr).hide(0);
    else
      $('[data-id]',tr).each(function() {
        var avant = $(this).hasClass("icon-ok");
        var apres = cases.parent().prevAll().find('.icon-ok[data-id="'+this.getAttribute('data-id')+'"]').length < cases.length/2;
        if ( avant != apres )
          $(this).toggleClass('icon-ok icon-nok').attr('title', (apres?'Établir':'Supprimer')+' l\'association à la matière de tous les cochés');
      }).show(0);
  }

  // Fonction d'association unique
  function association_um() {
    if ( $(this).parent().hasClass('fixe') ) {
      switch ( this.text ) {
        case '': popup('<p class="annonce">Il n\'est pas possible de supprimer l\'association de cette matière avec cet utilisateur : des notes de colles ou des transferts de documents sont concernés. Il faut les supprimer avant de supprimer l\'association utilisateur-matière.</p>',true); break;
        case 'P': popup('<p class="annonce">Il n\'est pas possible de supprimer l\'association de cette matière avec cet utilisateur : il est professeur (l\'association est peut-être supprimable dans la partie «&nbsp;Professeurs&nbsp;»).</p>',true); break;
        case 'C': popup('<p class="annonce">Il n\'est pas possible de supprimer l\'association de cette matière avec cet utilisateur : il est colleur (l\'association est peut-être supprimable dans la partie «&nbsp;Colleurs&nbsp;»).</p>',true); 
      }
    }
    else  {
      var val = $(this).hasClass("icon-ok");
      $.ajax({url: 'ajax.php',
              method: "post",
              data: { action:'utilisateur-matiere', id:$(this).parent().parent().data('id'), matiere:$(this).data('id'), val:1-val },
              dataType: 'json',
              el: $(this),
              fonction: function(el) {
                el.toggleClass('icon-ok icon-nok').attr('title', (val?'Établir':'Supprimer')+' l\'association à la matière');
              }
      });
    }
  }
  
  // Fonction d'association multiple
  function association_ums() {
    var cases = $(this).parent().parent().nextUntil('.categorie').find(':checked');
    if ( cases.length == 0 ) {
      affiche('<p>Aucune case n\'est cochée, aucune action ne peut être réalisée.</p>','nok');
      return
    }
    var lignes = cases.parent().parent();
    var ids = lignes.map(function() { return $(this).data('id'); }).get().join(',');
    var comptes = lignes.children(':first-of-type').map(function() { return $(this).text().split('(')[0].trim(); }).get().join(', ');
    var pos = comptes.lastIndexOf(',');
    if ( pos > 0 )
      comptes = comptes.substring(0,pos)+' et'+comptes.substring(pos+1);
    var val = $(this).hasClass("icon-ok");
    var mid = this.getAttribute('data-id');
    var question = val ? 'Vous allez établir l\'association à la matière '+$('#m'+mid).text()+' pour les comptes de '+comptes+'. Cela signifie que ces utilisateurs auront accès aux ressources liées à cette matière, en fonction de l\'autorisation que vous avez fixée pour ces ressources.' : 'Vous allez supprimer l\'association à la matière '+$('#m'+mid).text()+' pour les comptes de '+comptes+'. Cela signifie que ces utilisateurs n\'auront plus accès aux ressources liées à cette matière. Cela n\'est possible que si cela ne supprime pas des contenus parmi les notes de colles ou les transferts de documents. Les utilisateurs pour lesquels l\'association est matérialisée par un bouton <span class="icon-ok"></span> grisé ne seront pas traités.';
    confirmation(question, this, function(el) {
      $.ajax({url: 'ajax.php',
              method: "post",
              data: { action:'utilisateurs-matieres', ids:ids, matiere:mid, val:val|0 },
              dataType: 'json'
      });
    });
  }
  
}

// Édition des utilisateurs associés à un groupe
function init_utilisateurs_groupes() {
  // Cases à cocher : utilisation des groupes pour les mails et/ou les notes
  $('article input[type="checkbox"]').on("change",function() {
    $.ajax({url: 'ajax.php',
            method: "post",
            data: { action:'groupes', champ:this.id.substr(0,5), id:this.id.substr(5), val:(this.checked|0) },
            dataType: 'json',
            el: '',
            fonction: function(el) {
              return true;
            }
    });
  });
  // Édition des utilisateurs des groupes
  $('.usergrp span').append('&nbsp;<a class="icon-edite" title="Éditer les utilisateurs de ce groupe"></a>').on("click",utilisateursgroupe);
}

// Affichage du tableau de sélection des utilisateurs d'un groupe
function utilisateursgroupe() { 
  // Création de la fenêtre et variables
  popup($('#form-utilisateurs').html(),true);
  var f = $('#fenetre');
  var span = $(this);
  article = span.parent().parent();
  $('table', f).attr('id','ugrp');

  // Modification du groupe indiqué en titre
  $('h3', f).append($('.editable', article).text() || $('input:first', article).val());

  // Pliage/dépliage (les spans ont déjà été ajoutés)
  $('.icon-deplie', f).on("click", plie)

  // Cochage multiple
  $('.icon-cocher', f).on("click", cocher_utilisateurs);

  // Clic sur toute la ligne
  $('tr:not(.categorie)', f).on("click",function(e) {
    if ( !$(e.target).is('input') )
      $(this).find('input').click();
  });

  // Mise en évidence
  $('input', f).on("change",function() { $(this).parent().parent().toggleClass('sel',this.checked); });
  
  // Sélection automatique des utilisateurs déjà choisis
  var ids = span.data('uids').toString();
  $('#u'+ids.replace(/,/g,',#u'), f).prop("checked",true).change();

  // Récupération des valeurs et envoi
  $('.icon-ok', f).on("click",function() {
    var ids = $('input:checked', f).map(function() { return this.id.replace('u',''); }).get().join(',');
    var noms = $('input:checked', f).parent().prev().map(function() { return this.textContent.split('(')[0].trim(); }).get().join(', ') || '[Personne]';
    // Si formulaire d'ajout, simple mise à jour du formulaire et du span
    if ( article.is('div') ) {
      $('#uids', article).val(ids);
      span.data('uids',ids);
      span.html(noms+'&nbsp;<a class="icon-edite" title="Éditer les utilisateurs de ce groupe"></a>');
      $('#fenetre, #fenetre_fond').remove();
    }
    // Si groupe déjà existant, envoi des données
    else
      $.ajax({url: 'ajax.php',
              method: "post",
              data: { action:'groupes', champ:'utilisateurs', id:article.data('id'), uids:ids },
              dataType: 'json',
              el: span,
              fonction: function(el) {
                // Mise à jour de la liste des utilisateurs
                el.data('uids',ids);
                el.html(noms+'&nbsp;<a class="icon-edite" title="Éditer les utilisateurs de ce groupe"></a>');
                $('#fenetre, #fenetre_fond').remove();
              }
      });
  });
};

// Suppression massive des éléments d'une matière ou des informations d'une page
function suppressionmultiple() {
  var type = $(this).data('type');
  var contexte = $(this).parent().find('h3').text();
  var id = $(this).parent().data('id');
  var item = '';
  switch ( type ) {
    case 'infos': item = 'toutes les informations de la page <em>'+contexte+'</em>'; break;
    case 'progcolles': item = 'tous les programmes de colles de la matière <em>'+contexte+'</em>'; break;
    case 'cdt': item = 'tout le contenu du cahier de texte de la matière <em>'+contexte+'</em>'; break;
    case 'docs': item = 'tous les répertoires et documents de la matière <em>'+contexte+'</em>'; break;
    case 'notescolles': item = 'toutes les notes de la matière <em>'+contexte+'</em>'; break;
    case 'transferts': item = 'tous les transferts de documents personnels de la matière <em>'+contexte+'</em>'; break;
  }
  confirmation('Vous allez supprimer XXX.<br>Cette opération n\'est pas annulable.'.replace('XXX',item),this,function(el) {
    $.ajax({url: 'ajax.php',
            method: "post",
            data: 'action='+$('body').data('action')+'&id='+id+'&supprime_'+type+'=1',
            dataType: 'json',
            el: $(el),
            fonction: function(el) {
              el.remove();
            }
    });
  });
}

////////////////////////////////////////
// Paramétrage et envoi des courriels //
////////////////////////////////////////

// Facilités du formulaire d'envoi de courriel
$.fn.init_envoimail = function() {
  var form = $("#mail");
  // Reprise de init_docs
  $('input[type="file"]').attr('id','pj').on('change',function() {
    var fichiers = this;
    var tailletotale = 0;
    $('input[id^="nom"]').parent().remove();
    for (var i = 0, n = fichiers.files.length, s = 0, taille = ''; i < n; i++) {
      s = fichiers.files[i].size;
      tailletotale += s;
      taille = ( s < 1048576 ) ? Math.floor(s/1024)+' ko' : Math.floor(s/1048576)+' Mo';
      $('.ligne',form).last().after('<p class="ligne"><label for="nom'+i+'">Fichier '+(i+1)+'&nbsp;('+taille+')&nbsp;: </label><input type="text" name="nom[]" id="nom'+i+'" value="" size="50"></p>');
      $('#nom'+i).val(fichiers.files[i].name);
      if ( s > 5*1048576 )
        $('.ligne',form).last().addClass('fichierlourd');
    }
    $('#videpj,#infopj').toggle(!!fichiers.files.length);
    $('#infotaillepj').toggle(tailletotale > 20*1048576 || !!$('.fichierlourd').length);
  });
  
  // Bouton de vidage des pièces jointes, nécessaire ici
  $('#videpj').on('click', function() {
    $('#pj').wrap('<form>').closest('form').get(0).reset();
    $('#pj').unwrap().removeClass('nepassortir nok').bloque().prev().removeClass('nepassortir nok');
    $('input[id^="nom"]').parent().remove();
    $('#videpj,#infopj,#infotaillepj').hide(0);
  });
  
  // Formulaire de copie de lien vers un document du Cahier
  $('#mat').on('change',function() {
    $('#rep').html(reps[this.value]).change();
  });
  $('#rep').on('change',function() {
    $('#doc').html(docs[this.value]).change();
  });
  $('#doc').on('change',function() {
    if ( this.value < 1 )
      $('#liendoc').val('Copier le lien vers le document').prop('disabled',true).off('click').removeClass('ok');
    else
      $('#liendoc').val('Copier le lien vers le document '+$('#doc :selected').text()).prop('disabled',false).on('click', function() {
        var v = this.value;
        navigator.clipboard.writeText(window.location.href.replace(/mail.*/,'download?id='+$('#doc').val()))
          .then($(this).addClass('ok').val('Copié !'))
          .then( window.setTimeout(function() { $('#liendoc').val(v).removeClass('ok'); }, 2000) );
      });
  });
  
  // Gestion des utilisateurs/groupes déjà cochés 
  $('#form-destinataires tr.gr input:checked').each(function() {
    $('#form-destinataires').find('.dest[value='+this.value.replace(/,/g,'],.dest[value=')+']').prop("checked",true);
  });
  if ( $('#form-destinataires tr:not(.gr) .dest:checked').length ) {
    $('[name="id-copie"]').val( $('#form-destinataires tr:not(.gr) .dest:checked').map(function() { return this.value; }).get().join(',') );
    $('#maildest').text( $('#form-destinataires tr:not(.gr) .dest:checked').parent().prev().map(function() { return this.textContent; }).get().join(', ') );
  }
  // Suppression de la valeur initiale (avec "attr" pour que ce soit définitif)
  $('#form-destinataires input:checked').attr("checked",false);
}

// Affichage du tableau de sélection des utilisateurs destinataires d'un courriel
function destinatairesmail() {
  popup($('#form-destinataires').html(),true);
  var f = $('#fenetre');
  
  // Pliage/dépliage (les spans ont déjà été ajoutés, mais on les modifie ici
  // pour commencer avec les catégories "plie_init" initialement pliées)
  $('.icon-deplie', f).on("click", plie);
  $('tr.plie_init', f).nextUntil('.categorie').hide(0).children().addClass('cache');
  $('tr.plie_init', f).find('.icon-deplie').removeClass('icon-deplie').addClass('icon-plie');
  // Ajouts des identifiants pour la sélection automatique (début et groupes)
  $('tr:not(.gr) input.dest', f).attr('id',function() { return 'u'+this.value; });
  
  // Clic sur toute la ligne (deux premières cases seulement)
  $('tr:not(.categorie) td:nth-child(-n+2)', f).on("click",function(e) {
    if ( !$(e.target).is('input') )
      $(this).parent().find('input:first').click();
  });
  
  // Décochage automatique de l'autre case du même utilisateur et mise en évidence
  $('input[type="checkbox"]', f).on("change",function() {
    var tr = $(this).parent().parent();
    if ( this.checked )
      tr.find('input:not(.'+this.className+')').prop("checked",false);
    tr.toggleClass('sel',tr.find('input:checked').length>0);
    // Mise à jour des compteurs
    $('.nc', f).text($('tr:not(.gr) .dest:checked', f).length);
    $('.ncs', f).text($('tr:not(.gr) .dest:checked', f).length > 1 ? 's' : '');
    $('.ncc', f).text($('tr:not(.gr) .bcc:checked', f).length);
  });
  
  // Sélection automatique des utilisateurs déjà choisis
  var ids = $('[name="id-copie"]').val();
  $('#u'+ids.replace(/,/g,',#u')).prop("checked",true).change();
  ids = $('[name="id-bcc"]').val();
  $('#u'+ids.replace(/,/g,',#u')).parent().next().children().prop("checked",true).change();

  // Bouton de sélection multiple
  $('.categorie a', f).on("click keyup",function() {
    // Récupération des valeurs
    var classe = this.className.split(' ')[1]; // dest ou bcc
    var etat = (this.className.split(' ')[0] == 'icon-cocher');

    // Cochage et modifications
    $(this).parent().parent().nextUntil('.categorie').find('.'+classe+':not(:disabled)').prop('checked',etat).change();
    this.className = (etat?'icon-decocher ':'icon-cocher ')+classe;
    this.title = this.title.replace((etat?'Cocher':'Décocher'),(etat?'Décocher':'Cocher'));
    var classe2 = (classe == 'dest') ? 'bcc' : 'dest';
    $(this).parent().parent().find('.icon-decocher.'+classe2).each(function() {
      this.className = 'icon-cocher '+classe2;
      this.title = 'C'+this.title.substr(3); 
    });
  });
  
  // Groupes
  $('.gr input', f).on("click",function() {
    var ids = this.value;
    if ( this.className == 'dest' )
      $('#u'+ids.replace(/,/g,',#u')).prop("checked",this.checked).change();
    else
      $('#u'+ids.replace(/,/g,',#u')).parent().next().children().prop("checked",this.checked).change();
  });
  
  // Recherche d'utilisateur
  $('input[type="text"]', f).attr('id','recherche').on("input", function() {
    if (this.value.length >= 2) {
      $('tr:not(.categorie):not(:icontains("'+this.value+'"))', f).slice(1).hide(0);
      $('tr:not(.categorie):icontains("'+this.value+'")', f).show(0);
    }
    else {
      $('tr:not(.categorie)', f).filter(function() { return $(".cache",this).length; }).hide(0);
      $('tr:not(.categorie)', f).filter(function() { return !$(".cache",this).length; }).show(0);
    }
  });
  
  // Icône d'aide permettant d'afficher les paragraphes 
  $('.icon-aide', f).on("click", function() { $('p.aide', f).toggle(); }).click();
  
  // Récupération des valeurs
  $('.icon-ok', f).on("click",function() {
    $('[name="id-copie"]').val( $('tr:not(.gr) .dest:checked',f).map(function() { return this.value; }).get().join(',') );
    $('[name="id-bcc"]').val(   $('tr:not(.gr) .bcc:checked', f).map(function() { return this.value; }).get().join(',') );
    var destinataires = $('tr:not(.gr) .dest:checked', f).parent().prev().map(function() { return this.textContent; }).get();
    if ( !destinataires.length ) {
      destinataires = ['Vous'];
      $('textarea').next().hide(0);
    }
    else 
      $('textarea').next().show(0);
    $('#maildest').text(destinataires
                        .concat($('tr:not(.gr) .bcc:checked', f).parent().prev().prev().map(function() { return this.textContent+' (CC)'; }).get())
                        .join(', ') || '[Personne]' );
    $('#fenetre, #fenetre_fond').remove();
  });
}

// Envoi des courriels
function envoimail() {
  // Pas d'envoi sans sujet
  if ( !$('[name="sujet"]').val().length )
    affiche('Il faut un sujet non vide pour envoyer le courriel.','nok');
  // Envoi sans pièce jointe
  else if ( !$('#pj')[0].files.length )
    $.ajax({url: 'ajax.php',
            method: "post",
            data: $('#mail').serialize(),
            dataType: 'json'
    });
  // Envoi avec pièces jointes -- reprise de init_docs()
  else 
    $.ajax({url: 'ajax.php',
            method: "post",
            data: 'verifconnexion=1',
            dataType: 'json',
            el: '',
            fonction: function(el) {
              // Si transfert, pas d'affichage dans le div de log
              $('#log').hide(0);
              // Envoi réel du fichier ou des données
              var data = new FormData($('#mail')[0]);
              // Envoi
              $.ajax({url: 'ajax.php',
                      xhr: function() { 
                        // Évolution du transfert si fichier transféré
                        var xhr = $.ajaxSettings.xhr();
                        if ( xhr.upload && ( $('#pj')[0].files.length > 0 ) ) {
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

  // Pas de soumission classique si appel de cette fonction par
  // l'intermédiaire de submit sur le formulaire
  return false;
}

// Initialisation du tableau des autorisation d'envoi de courriels
// et du tableau d'édition des adresses électroniques
function init_envoimails() {
  
  var t = $('#envoimails');
  // Affichage des icônes de validation
  // La case de tableau HTML contient deux valeurs séparées de "|" :
  // l'identifiant du groupe de réception et la valeur 1 pour oui 0 pour non.
  // Le groupe d'émission est le data-id de la ligne.
  $('td',t).each(function() {
      var valeurs = this.textContent.split('|');
      this.innerHTML = ( valeurs[1] == 1 ) ? '<a class="icon-ok" data-id="'+valeurs[0]+'" title="Supprimer l\'autorisation d\'envoi"></a>'
                                           : '<a class="icon-nok" data-id="'+valeurs[0]+'" title="Établir l\'autorisation d\'envoi"></a>';
  });

  // Icônes de validation simple
  $('td a',t).on("click",function() {
    var val = $(this).hasClass("icon-nok")|0;
    $.ajax({url: 'ajax.php',
            method: "post",
            data: { action:'prefsglobales', id:'mails', depuis:$(this).parent().parent().data('id'), vers:$(this).data('id'), val:val },
            dataType: 'json',
            el: $(this),
            fonction: function(el) {
              el.toggleClass('icon-ok icon-nok').attr('title', (val?'Établir':'Supprimer')+' l\'autorisation d\'envoi');
            }
    });
  });

  // Icônes de validation multiple
  $('th span',t).on("click",function() {
    var val = $(this).hasClass("icon-ok");
    var ligne = $(this).parent().parent();
    $.ajax({url: 'ajax.php',
            method: "post",
            data: { action:'prefsglobales', id:'mails', depuis:ligne.data('id'), vers:0, val:val|0 },
            dataType: 'json',
            el: ligne,
            fonction: function(el) {
              el.find('td a').toggleClass('icon-ok',val).toggleClass('icon-nok',!val).attr('title', (val?'Établir':'Supprimer')+' l\'autorisation d\'envoi');
            }
    });
  });

}

///////////////////////////////////////
// Relève des déclarations de colles //
///////////////////////////////////////
function relevecolles() { 
  confirmation('<p>Vous allez réaliser une relève des déclarations de colles. Cela consiste à marquer comme relevées toutes les heures déclarées jusqu\'à maintenant et non encore relevées. Vous pourrez alors télécharger le nouveau relevé au sein du tableau en bas de page.</p><p>Cette opération n\'est pas annulable.</p><p>Une fois que vous aurez réalisé ce relevé, les professeurs et colleurs ne pourront pas modifier le nombre d\'élèves et la durée correspondant aux colles relevées.</p>',this,function() {
    $.ajax({url: 'ajax.php',
            method: "post",
            data: 'action=releve-colles&datemax='+$('#datemax').val(),
            dataType: 'json'
    });
  });
}

////////////////////////////////////////////////////////////////////////////
// Modification des éléments (nécessite le chargement complet de la page) //
////////////////////////////////////////////////////////////////////////////
$(function() {
  
  // Augmentation du padding en fonction du nombre d'icônes en haut à droite
  if ( $('#icones a').length > 2 )
    $('header h1').css('padding-right',( (1+$('#icones a').length) * 0.6 )+'em');
  
  // Affichage des accès/possibilités d'édition
  $(".affichable").attr('title',function() { return ( $(this).data('title') || $('#aide-'+this.id).text() ).replace(/(<([^>]+)>)/gi,''); }).on("click", affiche_titleplus);

  // Réglage du mode de lecture
  $('#icones .icon-lecture').reglagelecture();

  // Formulaires affichés par clic sur les icônes
  $('a.formulaire').on("click", formulaire);

  // Aide
  $('a.icon-aide').on("click",function() {
    popup($('#aide-'+( $(this).parent().data('action') || $('body').data('action') )).html(),false);
  });
  // Validation des formulaires déjà présents (pas pour les transformés)
  $('a.icon-ok').on("click", valide);

  // Boutons cache, montre, monte, descend, supprime, ajoute-colle
  $('a.icon-cache,a.icon-montre,a.icon-monte,a.icon-descend,a.icon-supprime,a.icon-ajoutecolle,a.icon-comms').on("click",function() {
    window[this.className.substring(5)]($(this));
  });

  // Édition en place des éléments de classe "editable"
  $('.editable').editinplace();

  // Blocaqe et validation automatique pour les formulaires déjà visibles
  $('form:visible').each(function() {
    $(this).find('input,select').not('.nonbloque').bloque().entreevalide($(this));
    // Sélecteur de couleur (pour les types d'agenda)
    $('[name="couleur"]').each(function() { $(this).colpick(); });
  });

  /////////////////////////////
  // Spécifique cahier de texte

  // Édition des propriétés des éléments des cahiers de texte
  $('p.titrecdt').editinplacecdt();

  // Édition des propriétés des raccourcis des cahiers de texte
  $('.cdt-raccourcis').init_cdt_raccourcis();

  ////////////////////
  // Spécifique agenda

  // Édition des propriétés des éléments des cahiers de texte
  $('p.titreagenda').editinplaceagenda();

  ////////////////////////
  // Spécifique transferts
  
  // Icône de lien rapide dans le tableau récapitulatif
  $('#transferts .icon-voir').on('click',function() {
    $('article[data-id=' + $(this).parent().data('id') + ']').remove('flash').deplace_viewport().addClass('flash');
  });
  $('article.transfert .icon-download').on("click", download_transfert);

  ///////////////////////////////
  // Spécifique envoi de courriel

  // Envoi de courriel
  $('.icon-mailenvoi').on("click", envoimail);
  
  // Édition des utilisateurs destinataires d'un courriel
  $('#maildest, #maildest + .icon-edite').on("click", destinatairesmail);
  
  // Initialisation : facilités pour l'envoi des pièces jointes 
  $('form#mail').on("submit", envoimail).init_envoimail();

  ///////////////////////////////
  // Spécifique réglages des utilisateurs, matières, groupes

  // Recherche d'utilisateur depuis la topbarre
  $('#rechercheutilisateurs input').on("input change", recherche_utilisateurs)

  // Affichage des icônes de pliage sur les lignes ".categorie" des tableaux d'utilisateurs
  $('.categorie th:first-child').prepend(
    $('<span class="icon-deplie" title="Déplier/Replier cette catégorie"></span>').on("click", plie)
  );

  // Gestion des matières
  $('article select[multiple]').init_selmult();
  
  // Boutons de suppression massive des éléments de matière et des informations d'une page
  $('.supprmultiple').on("click", suppressionmultiple);
  
  // Gestion des utilisateurs
  $('#u').each(init_utilisateurs);
  
  // Gestion des associations utilisateurs-matières
  $('#umats').each(init_utilisateurs_matieres);

  // Gestion des autorisations d'envoi de courriels
  $('#envoimails').each(init_envoimails);

  // Édition des utilisateurs des groupes - fonction à ne lancer qu'une fois
  $('.usergrp').first().each(init_utilisateurs_groupes);

  /////////////////////////////
  // Spécifique planning annuel

  // Modification des valeurs du planning
  $('#planning select').on("change",function() {
    $(this).parent().parent().find('input').prop('checked',this.value == 0).change();
  });
  $('#planning input').on("change",function() {
    if ( this.checked )  {
      $(this).parent().siblings().children('input').prop('checked',false).change();
      $(this).parent().siblings().children('select').val(0);
    }
    // Modification du total
    var d = $(this).attr('name').substr(0,1); // "c" ou "o"
    $('#n'+d).html( $('input[name^="'+d+'"]:checked').length );
  });
  
  //////////////////////////////////////////////
  // Spécifique relève de déclarations de colles
  $('#relevecolles').on("click", relevecolles);
  
});

