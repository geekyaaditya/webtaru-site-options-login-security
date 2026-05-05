document.addEventListener('DOMContentLoaded', function() {
    var backToTopBtn = document.querySelector('.wtols-back-to-top');
    
    if (backToTopBtn) {
        window.addEventListener('scroll', function() {
            if (window.pageYOffset > 300) {
                backToTopBtn.classList.add('wtols-btt-visible');
            } else {
                backToTopBtn.classList.remove('wtols-btt-visible');
            }
        });

        backToTopBtn.addEventListener('click', function(e) {
            e.preventDefault();
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        });
    }
});
