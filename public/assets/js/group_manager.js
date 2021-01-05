cmenu_dom = document.querySelector("#contextual-menu");
cmenu = $(cmenu_dom);
cmenu_height = cmenu_dom.getBoundingClientRect().height;
cmenu.hide();
window_height = document.querySelector("body").getBoundingClientRect().height;

let selectedSubject = null;
console.log(cmenu_height, window_height);

function openMenu(evt){
    selectedSubject = $(evt.target);
    let menuY = evt.pageY
    if (cmenu_height+evt.pageY > window_height){
        menuY -= cmenu_height;
    }
    cmenu.css({
        "display":"flex",
        "position":"absolute",
        "top": `${menuY}px`,
        "left": `${evt.pageX}px`
    });
}

function closeMenu(){
    cmenu.hide();
}

function moveSubject(evt){
    let toMove = selectedSubject;
    let tar = $(evt.target);
    let slot = $(tar.attr("target"));
    toMove.appendTo(slot);
    //toMove.remove()
    console.log(toMove, slot);
    let a = closeMenu();
}

$(".list-subject").on("click", openMenu);
$(".cmenu-item").on("click", moveSubject);
cmenu.on("mouseleave", closeMenu);