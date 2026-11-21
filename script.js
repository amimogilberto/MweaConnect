const menuToggle = document.querySelector(".menu-toggle");
const navBar = document.querySelector(".nav-bar");
const primaryNavigation = document.querySelector("#primary-navigation");

function closeMenu() {
    menuToggle.setAttribute("aria-expanded", "false");
    navBar.classList.remove("is-open");
}

menuToggle.addEventListener("click", function () {
    const isOpen = menuToggle.getAttribute("aria-expanded") === "true";
    menuToggle.setAttribute("aria-expanded", String(!isOpen));
    navBar.classList.toggle("is-open", !isOpen);
});

primaryNavigation.querySelectorAll("a").forEach(function (link) {
    link.addEventListener("click", closeMenu);
});

window.addEventListener("resize", function () {
    if (window.innerWidth > 700) {
        closeMenu();
    }
});
