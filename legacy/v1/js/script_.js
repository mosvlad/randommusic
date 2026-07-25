		//var URL = "randommusic.sytes.net:8999";
		
		//var local = "http://192.168.88.41:8999/"
		//var local = "http://100.127.213.143:8999/";
		
		var local = "http://randommusic.myddns.me:8999/";
		
		function randombg()
		{
			var images = ['1.jpg', '2.jpg','3.jpg', '4.jpg', '5.jpg', '6.jpg', '7.jpg']; //'1.jpg', '2.jpg', '3.jpg', '4.jpg', '5.jpg', '6.jpg', '7.jpg', 
			document.body.style.backgroundImage = 'url(img/' + images[Math.floor(Math.random() * images.length)] + ')';
			document.body.style.backgroundPosition = 'fixed';
			document.body.style.backgroundRepeat = 'no-repeat';

			GetNext();
		}
		
		
			function GetNext()
			{
				var element = document.getElementById("audio1");
				
				element.src = local + Math.round(Math.random() * 1300);
				
				document.getElementById("audio1").load();
			}
			
			function GetMessagesList()
			{
				var RequestBody = { "task":"getMessagesList"};
				
				var serverUrl =  local; 
				
			
				$.ajax({
					type: "POST",
					url: serverUrl,
					data: JSON.stringify(RequestBody),
					success: function (data) 
					{
						var ans = JSON.stringify(data);
						console.log(ans);
						var size = data.size;
						document.getElementById("answer").value = "";
						for (var i = 0; i < size; i++)
						
						{
							var name = "name" + i;
							var text = "text" + i;
							document.getElementById("answer").value += data[name];
							document.getElementById("answer").value += ":";
							document.getElementById("answer").value += data[text];
							document.getElementById("answer").value += "\n";
						}
						
						var textarea = document.getElementById('answer');
						textarea.scrollTop = textarea.scrollHeight;
					},
					contentType: 'application/json',
					dataType: 'json',
					crossDomain: true
				});
			}
			
			function SendMessage()
			{
				var RequestBody = {"task":"sendMessage", "name":"", "message":""};
				
				var serverUrl =  local; 
				
				RequestBody.name = document.getElementById("name").value;	
				RequestBody.message = document.getElementById("text").value;
				//document.getElementById("text").value = "";				
				$.ajax({
					type: "POST",
					url: serverUrl,
					data: JSON.stringify(RequestBody),
					success: function (data) 
					{
						var ans = JSON.stringify(data);
						console.log(ans);
						document.getElementById("text").value = "";	
					},
					contentType: 'application/json',
					dataType: 'json',
					crossDomain: true
				});
			}
			
			function auth()
			{
				window.open("auth.html", "Авторизация", "width=720,height=420");
			}
		
			function Init()
			{
				var timerId = setInterval(function() {
				GetMessagesList();
				}, 2000);
			}
			
			function onEnter(e) 
			{
				if (e.keyCode == 13) 
				{
					SendMessage();					
				}
			}