//epsilon-not.net javascript

//html5 fix for IE
(function(elements) {
	if (window.navigator.appName == 'Microsoft Internet Explorer' && document.documentMode < 9) {
		for (var i = 0; i < elements.length; i++)  
			document.createElement(elements[i]);
	}
})(['header', 'hgroup', 'footer', 'aside', 'section', 'article', 'nav', 'figure', 'figcaption', 'time', 'mark', 'meter']);

window.onload = function() {
	//make placeholder work for legacy browsers
	(function(elements) { 
		if (!('placeholder' in document.createElement('input'))) {
			for (var i = 0; i < elements.length; i++) {
				var placeholder = elements[i].getAttribute('placeholder');
				if (placeholder) {
					elements[i].onfocus = function() { 
						if (this.value == this.getAttribute('placeholder')) {
							this.value = ''; 
							this.style.color = '';
						}
					};
					elements[i].onblur = function() { 
						var placeholder = this.getAttribute('placeholder');
						if (this.value == '' || this.value == placeholder) {
							this.value = placeholder; 
							this.style.color = '#a9a9a9';
						}
					};
					elements[i].onblur();
				}
			}
		}
	})(document.getElementsByTagName('input'));
};