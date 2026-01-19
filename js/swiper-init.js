document.addEventListener("DOMContentLoaded", function () {
    // Select all Swiper containers
    const swiperContainers = document.querySelectorAll(".swiper-icons");

    swiperContainers.forEach((container) => {
        // Read attributes
        const slidesPerView = container.getAttribute("data-slides-per-view") || 1;
        const navigationAttr = container.getAttribute("data-navigation");
        const autoplayAttr = container.getAttribute("data-autoplay");
        const speedAttr = container.getAttribute("data-speed");
        const effectAttr = container.getAttribute("data-effect");
        const gridAttr = container.getAttribute("data-grid");
        const loopAttr = container.getAttribute("data-loop");
        const spaceAttr = container.getAttribute("data-space-between");

        // Parse navigation attribute
        const enableNavigation = navigationAttr === "true";
        // Parse loop attribute
        const enableLoop = loopAttr === "true";

        // Parse autoplay attribute
        let autoplay = false; // Default to no autoplay
        if (autoplayAttr === "true") {
            autoplay = {
                delay: 4000
            }; // Default delay of 4 seconds
        } else if (!isNaN(autoplayAttr)) {
            autoplay = {
                delay: parseInt(autoplayAttr, 10)
            };
        }

        // Initialize Swiper
        new Swiper(container, {
            breakpoints: {
                // when window width is >= 320px
                320: {
                    slidesPerView: 2,
                    spaceBetween: 10,
                },
                // when window width is >= 480px
                480: {
                    slidesPerView: 3,
                    spaceBetween: 10,
                },
                900: {
                    slidesPerView: parseInt(slidesPerView, 10),
                    spaceBetween: 10,
                },
            },

            centeredSlides: true,
            //	    centeredSlidesBounds: true,
            loopAddBlankSlide: true,
            parallax: true,

            speed: speedAttr,
            loop: enableLoop, // Enable/Disable looping
            slidesPerView: parseInt(slidesPerView, 10), // Number of slides visible
            spaceBetween: spaceAttr, // Space between slides
            autoplay: autoplay, // Configure autoplay
            //	   autoplay: { delay: 1000 };
            navigation: enableNavigation ?
                {
                    nextEl: container.querySelector(".swiper-button-next"),
                    prevEl: container.querySelector(".swiper-button-prev"),
                } :
                false, // Disable navigation if false
        });

        // Conditionally hide navigation buttons if navigation is disabled
        if (!enableNavigation) {
            const nextButton = container.querySelector(".swiper-button-next");
            const prevButton = container.querySelector(".swiper-button-prev");
            if (nextButton) nextButton.style.display = "none";
            if (prevButton) prevButton.style.display = "none";
        }
    });
});


// Posts Slider
document.addEventListener("DOMContentLoaded", function () {
    // Select all Swiper containers
    const swiperContainers = document.querySelectorAll(".swiper-posts");

    swiperContainers.forEach((container) => {
        // Read attributes
        const slidesPerView = container.getAttribute("data-slides-per-view") || 1;
        const navigationAttr = container.getAttribute("data-navigation");
        const autoplayAttr = container.getAttribute("data-autoplay");
        const speedAttr = container.getAttribute("data-speed");
        const effectAttr = container.getAttribute("data-effect");
        const gridAttr = container.getAttribute("data-grid");
        const loopAttr = container.getAttribute("data-loop");
        const spaceAttr = container.getAttribute("data-space-between");

        // Parse navigation attribute
        const enableNavigation = navigationAttr === "true";
        // Parse loop attribute
        const enableLoop = loopAttr === "true";

        // Parse autoplay attribute
        let autoplay = false; // Default to no autoplay
        if (autoplayAttr === "true") {
            autoplay = {
                delay: 4000
            }; // Default delay of 4 seconds
        } else if (!isNaN(autoplayAttr)) {
            autoplay = {
                delay: parseInt(autoplayAttr, 10)
            };
        }

        // Initialize Swiper
        new Swiper(container, {
            breakpoints: {
                // when window width is >= 320px
                320: {
                    slidesPerView: 2,
                    spaceBetween: 10,
                },
                480: {
                    slidesPerView: 3,
                    spaceBetween: 10,
                },
                900: {
                    slidesPerView: parseInt(slidesPerView, 10), // Number of slides visible
                    spaceBetween: 10,
                },
            },
            /*
            	effect: 'coverflow',
            	coverflowEffect: {
            	  rotate: 5,
            	  slideShadows: false,
            	  scale: 1;
            	},
            */
            //effect: fade,
            //crossFade: true,
            centerInsufficientSlides: true,
            //          centeredSlidesBounds: true,
            loopAddBlankSlide: true,
            parallax: true,

            speed: speedAttr,
            loop: enableLoop, // Enable/Disable looping
            slidesPerView: parseInt(slidesPerView, 10), // Number of slides visible
            spaceBetween: spaceAttr, // Space between slides
            autoplay: autoplay, // Configure autoplay
            //     autoplay: { delay: 1000 };
            navigation: enableNavigation ?
                {
                    nextEl: container.querySelector(".swiper-button-next"),
                    prevEl: container.querySelector(".swiper-button-prev"),
                } :
                false, // Disable navigation if false
        });

        // Conditionally hide navigation buttons if navigation is disabled
        if (!enableNavigation) {
            const nextButton = container.querySelector(".swiper-button-next");
            const prevButton = container.querySelector(".swiper-button-prev");
            if (nextButton) nextButton.style.display = "none";
            if (prevButton) prevButton.style.display = "none";
        }
    });
});