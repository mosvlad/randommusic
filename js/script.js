
var paths;


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

function randombg()
{
	var images = ['1.jpg', '2.jpg','3.jpg', '4.jpg', '5.jpg', '6.jpg', '7.jpg']; //'1.jpg', '2.jpg', '3.jpg', '4.jpg', '5.jpg', '6.jpg', '7.jpg', 
	document.body.style.backgroundImage = 'url(img/' + images[Math.floor(Math.random() * images.length)] + ')';
	//document.body.style.backgroundPosition = 'fixed';
	//document.body.style.backgroundRepeat = 'no-repeat';

	loadFile("paths.conf");
	GetNext();
}
		
function GetNext()
{
	
	gtag('ButtonNext', 'Next');
	window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}
gtag('js', new Date());

gtag('config', 'UA-141793479-1');
	
	var element = document.getElementById("audio1");
	var currentPath = paths[Math.floor(Math.random() * paths.length)];
	
	element.src = currentPath;
	
	ID3.loadTags(currentPath, function() {
    var tags = ID3.getAllTags(currentPath);
	document.getElementById("title").innerHTML = tags.artist + " - " + tags.title;
    console.log(tags.artist + " - " + tags.title + ", " + tags.album);
});
		
	document.getElementById("audio1").load();
}