var $j = jQuery.noConflict();
/* ---------------------------------------------------- */
/*	Parallax											*/
/* ---------------------------------------------------- */

	jQuery.fn.parallax = function(xpos, speedFactor) {

		var firstTop, methods = {};

		return this.each(function(idx, value) {

			var $this = jQuery(value), firstTop = $this.offset().top;

			if (arguments.length < 1 || xpos === null)

				xpos = "50%";

			if (arguments.length < 2 || speedFactor === null)

				speedFactor = 0.1;

			methods = {

				update: function() {

					var pos = jQuery(window).scrollTop();

					$this.each(function() {

						$this.css('backgroundPosition', xpos + " " + Math.round((firstTop - pos) * speedFactor) + "px");

					});

				},

				init: function() {

					this.update();

					jQuery(window).on('scroll', methods.update);

				}

			}

			return methods.init();

		});

	};	
//mobile menu


jQuery(document).ready(function(){
	
	jQuery('#menu-main').superfish();
    jQuery('#menu-main').sktmobilemenu();

});

(function( $ ) {
 
	$.fn.sktmobilemenu = function( options ) { 
	
		var defaults = {
			'fwidth': 700
		};

		//call in the default otions
		var options = $.extend(defaults, options);
		var obj = $(this);

		return this.each(function() {
		
			if($(window).width() < options.fwidth) {
				sktMobileRes();
			}
			
			$(window).resize(function() {
				if($(window).width() < options.fwidth) {
					sktMobileRes();
				}else{
					sktDeskRes();
				}
			});
			
			function sktMobileRes() {
				obj.addClass('skt-mob-menu').hide();
				obj.parent().css('position','relative');
				if(obj.prev('.sktmenu-toggle').length === 0) {
					obj.before('<div class="sktmenu-toggle" id="responsive-nav-button"></div>');
				}
				obj.parent().find('.sktmenu-toggle').removeClass('active');
			}
			
			function sktDeskRes() {
				obj.removeClass('skt-mob-menu').show();
				if(obj.prev('.sktmenu-toggle').length) {
					obj.prev('.sktmenu-toggle').remove();
				}
			}
			
			obj.parent().on('click','.sktmenu-toggle',function() {
			
				if(!$(this).hasClass('active')){
					$(this).addClass('active');
					$(this).next('ul').stop(true,true).slideDown();
				}
				else{
					$(this).removeClass('active');
					$(this).next('ul').stop(true,true).slideUp();
				}
			});
		});
	};
})( jQuery );




jQuery(window).load(function(){

jQuery('.full-bg-image-fixed').parallax("center", 0.2);
jQuery('.full-bg-breadimage-fixed').parallax("center", 0.2);


});


jQuery(document).ready(function ($) {



	jQuery("a[data-rel^='prettyPhoto']").prettyPhoto({

		animation_speed:'normal',

		theme:'facebook',

		slideshow:3000,

		show_title:false,

		autoplay_slideshow: false	

	});

	
    
	document.getElementById('s') && document.getElementById('s').focus();
});


jQuery(document).ready( function() {

  //Back to top
	jQuery('#back-to-top,#backtop').hide();
	jQuery(window).scroll(function() {
		if (jQuery(this).scrollTop() > 100) {
			jQuery('#back-to-top,#backtop').fadeIn();
		} else {
			jQuery('#back-to-top,#backtop').fadeOut();
		}
	});

	jQuery('#back-to-top,#backtop').click(function(){
		jQuery('html, body').animate({scrollTop:0}, 'slow');
	});
	
});
