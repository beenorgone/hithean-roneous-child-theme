document.addEventListener("DOMContentLoaded", () => {
    const popup = document.getElementById("popup");
    const closePopup = document.querySelector(".close-popup");
    const popupTriggers = document.querySelectorAll(".click-to-popup");

    popupTriggers.forEach((trigger) => {
        trigger.addEventListener("click", () => {
            if (popup) popup.style.display = "block";
        });
    });

    if (closePopup) {
        closePopup.addEventListener("click", () => {
            popup.style.display = "none";
        });
    }

    window.addEventListener("click", (e) => {
        if (e.target === popup) {
            popup.style.display = "none";
        }
    });
});
