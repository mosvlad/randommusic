var paths;

$(document).ready(function MobileCheck()
{
  if (screen.width < 700)
  {
    //alert("Фоны для мобилочки скоро поправлю! ^_^");
  }
});

function loadFile(filePath) {
  var result = null;
  var xmlhttp = new XMLHttpRequest();
  xmlhttp.open("GET", filePath, false);
  xmlhttp.send();
  if (xmlhttp.status==200) {
    result = xmlhttp.responseText;
  }
    
  paths = result.split("\n");
}

function Init()
{
  setTimeout(function (){ randomBg() }, 100);
  
  loadFile("paths.conf");
  getNext();
}

function randomBg()
{
  var bgNode = document.getElementById("main-bg");

  //bgNode.style.backgroundImage = 'url(./img/bg/' + (Math.floor(Math.random() * 19) + 1) + ".jpg" + ')';
  bgNode.style.backgroundImage = "url('./img/bg/" + (Math.floor(Math.random() * 19) + 1) + ".jpg')";
}

function getNext()
{
  var tags, titleStr;

  gtag('ButtonNext', 'Next');
  window.dataLayer = window.dataLayer || [];
  function gtag(){ dataLayer.push(arguments) }
  gtag('js', new Date());
  gtag('config', 'UA-141793479-1');
  
  var playerNode  = document.getElementById("audio-player");
  var currentPath = paths[Math.floor(Math.random() * paths.length)];
  
  playerNode.src = currentPath;
  
  ID3.loadTags(currentPath, function() {
    tags = ID3.getAllTags(currentPath);
    titleStr = tags.artist + " - " + tags.title + " ";
    document.getElementById("song-name").innerHTML = titleStr;
    document.title = titleStr;
    
    //(function titleScroller(text) {
      //document.title = text;
      //setTimeout(function () {
      //    titleScroller(text.substr(1) + text.substr(0, 1));
      //}, 250);
    //}(titleStr));
      
    //console.log(tags.artist + " - " + tags.title + ", " + tags.album);
  });
  
  playerNode.load();
}