// Add new pins with } else if (this.classList.contains("culture-tab")) {
// showView("culture");

document.addEventListener("DOMContentLoaded", function () {
    const tabs = document.querySelectorAll(".pin-tab");
    const views = document.querySelectorAll(".pin-view");

    function showView(name) {
        tabs.forEach(tab => {
            tab.classList.toggle("active", tab.classList.contains(name + "-tab"));
        });

        views.forEach(view => {
            view.style.display = view.classList.contains(name + "-view") ? "block" : "none";
        });
    }

    tabs.forEach(tab => {
        tab.style.cursor = "pointer";

        tab.addEventListener("click", function () {
            if (this.classList.contains("activism-org-tab")) {
                showView("activism-org");
            } else if (this.classList.contains("activism-pub-tab")) {
                showView("activism-pub");
            }
        });
    });

    // Start with Orgs
    showView("activism-org");
});
