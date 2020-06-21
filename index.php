<?php
// Start the session
session_start();
?>
<?php
require 'vendor/autoload.php';
require 'config.php';

use ezsql\Database;
use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;
use ezsql\Config; // Load ezSQL Config Class
use ezsql\Database\ez_mysqli; // Load ezSQL driver Class



function sendEmail($from,$subject,$to,$plaintext,$htmlbody){
    $email = new \SendGrid\Mail\Mail();

    foreach($from as $f){
        $email->setFrom($f[0], $f[1]);
    }

    $email->setSubject($subject);
    $duplicateCheck = [];
    foreach($to as $t){
        if(in_array($t[0],$duplicateCheck)){
            continue;
        }
        if(!empty($t[0]) && $t[0] != null) {
            $email->addTo($t[0], $t[1]);
            $duplicateCheck[] = $t[0];
        }

    }
    $email->addContent("text/plain", $plaintext);
    $email->addContent(
        "text/html", $htmlbody
    );

    $file = fopen(ROOT_PATH."/sendgridapikey","r");
    $apiKey = fgets($file);
    fclose($file);

    $sendgrid = new \SendGrid($apiKey);

    try {
        $response = $sendgrid->send($email);
        $r = $response->body();
    } catch (Exception $e) {
        echo 'Caught exception: '. $e->getMessage() ."\n";
    }
}

function is_user(){
    if(isset($_SESSION["user_id"])){
        return true;
    }
    return false;
}

function user_id(){
    if(is_user()){
        return $_SESSION["user_id"];
    }
    return false;
}



$settings = new Config('mysqli', ["root", "8751qwer", "pcs"]);
$db = new ez_mysqli($settings);



if(isset($_POST['id_token'])){
$client = new Google_Client(['client_id' => "684616953826-c61nkc5h7mbilnjmoi0b5lgl3jogl3qu.apps.googleusercontent.com"]);  // Specify the CLIENT_ID of the app that accesses the backend
$client->setAuthConfigFile(ROOT_PATH.'/client_secret_992582993320-mlcdu3rrjnfnoot72oeioaacpp5cjnhc.apps.googleusercontent.com.json');
$type = "google";
try{
    $payload = $client->verifyIdToken($_POST['id_token']);
    //$attributes = $payload->getAttributes();
    $picture = $payload['picture'];
    $_SESSION['user_thumb'] = $picture;
    $id = $payload['sub'];
    $_SESSION['user_id'] = $id;
    $email = $payload['email'];
    $_SESSION['email'] = $email;
    $username = $payload['name'];
    $_SESSION['name'] = $username;
    $db->query("INSERT INTO users (username,id,email,thumbnail) VALUES('".$username."',".$id.",'".$email."','".$picture."')");
    echo json_encode(["Logged in"]);
    exit();
}
catch(exception $e){
    echo json_encode([$e->getMessage()]);
    exit();
}
}

if(isset($_POST['action'])){

    $video = $db->get_row("SELECT * FROM videos where id=".$_POST["video_id"]);
    $user = $db->get_row("SELECT * from users where id=".$video->user_id);
    if($_POST["action"] =="approve")
    {
        $db->query("UPDATE videos set approved = 1 where id=".$_POST["video_id"]);
        $video = $db->get_row("SELECT * FROM videos where id=".$_POST["video_id"]);


        if(empty($user)){
            echo json_encode(["success"=>"false","message" =>"Could not find user that shared video","vid" => $_POST["video_id"]]);
       exit();
        }




        if($video->approved == 1){
            //Send Email to Poster
            $sender_email[] = [SENDER_EMAIL,"PoliceCrimeStoppers.com"];
            $recipient_email[] = [$user->email,$user->username];
            $subject = "PoliceCrimeStoppers.com :: Your video has been approved and shared with ".SUBSCRIBER_COUNT." of our members";
            $plaintext= "PoliceCrimeStoppers.com :: Your video has been shared";
            $html_body = "";
            $html_body .= "<h1>We shared your video '".$video->title."' with ".SUBSCRIBER_COUNT." of our followers</h1><br>";
            $html_body .= "<img style='width:300px;height:225px;' src='".$video->thumbnail."' /><br>";
            $html_body .= "<a href='".SITE_URL."?v=".$video->id."'>Click here to be taken to the video on the site</a>";
            sendEmail($sender_email,$subject,$recipient_email,$plaintext,$html_body);

            //Send Email to all subscribers
            $users = $db->get_results("SELECT * FROM USERS WHERE subscribe =1;");
            $sender_email =  [];
            $recipient_email = [];
            $sender_email[] = [SENDER_EMAIL,"PoliceCrimeStoppers.com"];
            foreach($users as $user){
                $recipient_email[] = [$user->email,$user->username];
            }
            $subject = "PoliceCrimeStoppers.com :: Someone shared a new video '".$video->title."'";
            $plaintext = "PoliceCrimeStoppers.com :: Someone shared a new video";
            $html_body = "";
            $html_body .= "<h1>New Video '".$video->title."'</h1><br>";
            $html_body .= "<img style='width:300px;height:225px;' src='".$video->thumbnail."' /><br>";
            $html_body .= "<a href='".SITE_URL."?v=".$video->id."'>Click here to be taken to the video on the site</a><br>";
            $html_body .= "<br>Reply to this email to stop receiving these emails";
            sendEmail($sender_email,$subject,$recipient_email,$plaintext,$html_body);

            echo json_encode(["success"=>"true","vid" => $_POST["video_id"]]);
            exit();
        }
        else{
            echo json_encode(["success"=>"false","message"=>"There was a problem approving this video","vid" => $_POST["video_id"]]);
            exit();
        }

    }
    elseif($_POST["action"]=="reject"){

        $db->query("UPDATE videos set approved = 2 where id=".$_POST["video_id"]);

        $video = $db->get_row("SELECT * FROM videos where id=".$_POST["video_id"]);
        if($video->approved == 2){
            //Send Email to Poster
            $sender_email[] = [SENDER_EMAIL,"PoliceCrimeStoppers.com"];
            $recipient_email[] = [$user->email,$user->username];
            $subject = "PoliceCrimeStoppers.com :: Your video has been rejected because the content does not match site requirements";
            $plaintext= "PoliceCrimeStoppers.com :: Your video has been rejected";
            $html_body = "<h1>We rejected your video '".$video->title."'</h1><br>";
            $html_body .= "<img style='width:300px;height:225px' src='".$video->thumbnail."' />";
            $html_body .= "<br>Reply to this email if you require further explanation";
            sendEmail($sender_email,$subject,$recipient_email,$plaintext,$html_body);

            echo json_encode(["success"=>"true","vid" => $_POST["video_id"]]);
            exit();
        }
        else{
            echo json_encode(["success"=>"false","message"=>"Unable to reject video","vid" => $_POST["video_id"]]);
            exit();
        }
    }
}


if(isset($_POST["upload"])){

    //upload mp4
    //return embed the newly uploaded video

    if(!is_user()){
        echo json_encode(["success"=>"false","message"=>"You must be logged in to share videos"]);
    exit();
    }

    if(!empty($_POST["youtube_id"]) && $_POST["youtube_id"] != "false"){
        $result = $db->get_row("SELECT * from videos where youtube_id='".$_POST["youtube_id"]."'");
        if(!empty($result)){
            echo json_encode(["success"=>"false","message"=>"This video has already been shared"]);
            exit();
        }
        $video_info = file_get_contents('https://www.youtube.com/get_video_info?video_id=' . $_POST["youtube_id"]);
        parse_str($video_info, $video_info_array);
        $result = json_decode($video_info_array['player_response']);
        $title = json_decode($video_info_array['player_response'])->videoDetails->title;
        $thumbnail = $result->videoDetails->thumbnail->thumbnails[3]->url;
        $db->query("INSERT INTO videos (region_id,youtube_id,user_id,thumbnail,title) VALUES('".$_POST["region"]."','".$_POST["youtube_id"]."',".user_id().",'".$thumbnail."','".$db->escape($title)."')");


        $video = $db->get_row("SELECT * from videos where id='".$db->getInsertId()."'");

        $user = $db->get_row("SELECT * From users where id=".user_id());
//Send Email to Poster and admin
        $sender_email[] = [SENDER_EMAIL,"PoliceCrimeStoppers.com"];
        $recipient_email[] = [$user->email,$user->username];
        $recipient_email[] = [SENDER_EMAIL,"PoliceCrimeStoppers.com"];
        $subject = "PoliceCrimeStoppers.com :: Your video is under review for sharing with ".SUBSCRIBER_COUNT." of our followers";
        $plaintext= "PoliceCrimeStoppers.com :: Your video is under review";
        $html_body = "<h1>We are reviewing your video '".$video->title."'</h1><br>";
        $html_body = "<img src='".$video->thumbnail."' style='width:300px;height:225px;'/><br>";
        $html_body .= "<strong>Once your video has been approved or rejected, you will receive an additional email</strong><br>";
        $html_body .= "<a target='_blank' href='".SITE_URL."?v=".$video->id."'>Click here to be taken to the video on the site</a>";
        sendEmail($sender_email,$subject,$recipient_email,$plaintext,$html_body);

        echo json_encode(["success"=>"true","message"=>"Shared a new video, pending review","youtube_id" => $_POST["youtube_id"]]);
        exit();
    }
    else{

        $path_parts = pathinfo($_FILES["videofile"]["name"]);

        if(!$path_parts['extension'] == "mp4")
        {
            echo json_encode(["success"=>"false","message"=>"We only support uploading .mp4 videos"]);
            exit();
        }

        $video = $_FILES["videofile"]["tmp_name"];
        shell_exec(FFMPEG." -i $video -deinterlace -an -ss 1 -t 00:00:01 -r 1 -y -vcodec mjpeg -f mjpeg ".THUMBNAIL_PATH."/".md5($_FILES["videofile"]["name"].$_FILES["videofile"]["size"]).".jpg 2>&1");

        $result = $db->get_row("SELECT * from videos where keyname='".md5($_FILES["videofile"]["name"].$_FILES["videofile"]["size"])."'");
        if(!empty($result)){
            echo json_encode(["success"=>"false","message"=>"This video has already been shared"]);
            exit();
        }
        $s3 = new S3Client([
            'version' => 'latest',
            'region'  => 'ca-central-1'
        ]);

        try {
            // Upload data.
            $result = $s3->putObject([
                'Bucket' => "policecrimestoppers",
                'Key'    => md5($_FILES["videofile"]["name"].$_FILES["videofile"]["size"]).".jpg",
                'SourceFile' => THUMBNAIL_PATH."/".md5($_FILES["videofile"]["name"].$_FILES["videofile"]["size"]).".jpg",
                'ACL'    => 'public-read',
                'ContentType' => "image/jpeg",
            ]);
            $videoThumbnail = $result['ObjectURL'];
        } catch (S3Exception $e) {
            echo $e->getMessage() . PHP_EOL;
        }

        try {
            // Upload data.
            $result = $s3->putObject([
                'Bucket' => "policecrimestoppers",
                'Key'    => md5($_FILES["videofile"]["name"].$_FILES["videofile"]["size"]).".mp4",
                'SourceFile' => $_FILES["videofile"]["tmp_name"],
                'ACL'    => 'public-read',
                'ContentType' => "audio/mpeg",
            ]);
            $result['ObjectURL'];
            $db->query("INSERT INTO videos (region_id,title,source,user_id,keyname,thumbnail) VALUES('".$_POST["region"]."','".$_FILES["videofile"]["name"]."','".$result['ObjectURL']."','".user_id()."','".md5($_FILES["videofile"]["name"].$_FILES["videofile"]["size"])."','".$videoThumbnail."')");
            unlink(THUMBNAIL_PATH."/".md5($_FILES["videofile"]["name"].$_FILES["videofile"]["size"]).".jpg");

            //Send Email to Poster
            $sender_email[] = [SENDER_EMAIL,"PoliceCrimeStoppers.com"];
            $recipient_email[] = [$user->email,$user->username];
            $recipient_email[] = [SENDER_EMAIL,"PoliceCrimeStoppers.com"];
            $subject = "PoliceCrimeStoppers.com :: Your video is under review for sharing with ".SUBSCRIBER_COUNT." of our members";
            $plaintext= "PoliceCrimeStoppers.com :: Your video is under review";
            $html_body = "<h1>We are reviewing your video '".$video->title."'</h1><br>";
            $html_body .= "<strong>Once your video has been approved or rejected, you will receive an additional email</strong>";
            $html_body .= "<a target='_blank' href='".SITE_URL."?v=".$video->id."'>Click here to be taken to the video on the site</a>";
            sendEmail($sender_email,$subject,$recipient_email,$plaintext,$html_body);
            echo json_encode(["success"=>"true","message"=>"Shared a new video, pending review","url" => $result['ObjectURL']]);
            exit();
        } catch (S3Exception $e) {
            echo $e->getMessage() . PHP_EOL;
        }

    }



}
$regions = $db->get_results("SELECT *,Count(videos.region_id) as video_count FROM regions,videos where regions.id = videos.region_id Group by(videos.region_id) order by video_count DESC");



if(isset($_GET["r"]) && $_GET["r"] != 0){
    $approvedVideos= $db->get_results("SELECT videos.id,regions.name,videos.created,videos.title,videos.source,videos.youtube_id,videos.thumbnail,users.username FROM videos,regions,users where videos.user_id = users.id AND videos.region_id = regions.id AND videos.approved='1' AND videos.region_id=".$_GET["r"]." ORDER BY created DESC limit 50 ");
    if(user_id() == 101554483107832567917){
        $pendingApproval = $db->get_results("SELECT * FROM videos where approved='0' AND videos.region_id=".$_GET["r"]." ORDER BY created ASC limit 50 ");
    }
}
else{
    $approvedVideos= $db->get_results("SELECT videos.id,regions.name,videos.created,videos.title,videos.source,videos.youtube_id,videos.thumbnail,users.username FROM videos,regions,users where videos.user_id = users.id AND videos.region_id = regions.id AND videos.approved='1' ORDER BY created DESC limit 50 ");
    if(user_id() == 101554483107832567917){
        $pendingApproval = $db->get_results("SELECT * FROM videos where approved='0' ORDER BY created ASC limit 50 ");
    }
}


if(isset($_GET["v"])){
    $currentVideo = $db->get_row("Select * from videos,users where videos.user_id = users.id AND videos.id=".$_GET["v"]);
}
else{
    $currentVideo = $approvedVideos[0];
    header("Location: ".SITE_URL."?v=".$currentVideo->id);
}

?>
<html>
<head>
    <script
        src="https://code.jquery.com/jquery-3.5.1.min.js"
        integrity="sha256-9/aliU8dGd2tb6OSsuzixeV4y/faTqgFtohetphbbj0="
        crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.0/dist/umd/popper.min.js" integrity="sha384-Q6E9RHvbIyZFJoft+2mJbHaEWldlvI9IOYy5n3zV9zzTtmI3UksdQRVvoxMfooAo" crossorigin="anonymous"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.0/js/bootstrap.min.js" integrity="sha384-OgVRvuATP1z7JjHLkuOU7Xw704+h835Lr+6QL9UvYjZE3Ipu6Tp75j7Bh/kR0JKI" crossorigin="anonymous"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/jquery-toast-plugin/1.3.2/jquery.toast.min.css" crossorigin="anonymous">
    <script src='https://cdnjs.cloudflare.com/ajax/libs/jquery-toast-plugin/1.3.2/jquery.toast.js'></script>
    <script>

    function onSignIn(googleUser) {
        var profile = googleUser.getBasicProfile();
        var id_token = googleUser.getAuthResponse().id_token;
        console.log('ID Token: ' + id_token);
        console.log('ID: ' + profile.getId()); // Do not send to your backend! Use an ID token instead.
        console.log('Name: ' + profile.getName());
        console.log('Image URL: ' + profile.getImageUrl());
        console.log('Email: ' + profile.getEmail()); // This is null if the 'email' scope is not present.
        console.log(profile);

        jQuery.post("index.php", {
            id_token: id_token,
            ajax: true
        }, function (data) {
            $.toast({
                text :"You are now Subscribed",
                hideAfter: 3000
            });

            window.location.reload();
        });
    }


    $( document ).ready(function() {

        function youtube_parser(url){
            var regExp = /^.*((youtu.be\/)|(v\/)|(\/u\/\w\/)|(embed\/)|(watch\?))\??v?=?([^#&?]*).*/;
            var match = url.match(regExp);
            return (match&&match[7].length==11)? match[7] : false;
        }
        $("select").select2();

        $(".share-video").click(function(){
            console.log("ShareVideo");
            $('#share-video-dialog').modal();
        });

        $(".approve").click(function(){
            $.toast({
                text :"Approving Video",
                hideAfter: 3000
            });
            jQuery.post("index.php", {
                action: "approve",
                video_id: $(this).attr("video_id")
            }, function (data) {
                result = data;
                if(result.success){
                    //remove video
                    nextVideo();
                    $(".vid-"+result.vid).remove();
                    $.toast({
                        text: "Video has been Approved",
                        heading: "Approvals",
                        icon: "info",
                        showHideTransition: 'fade',
                        allowToastClose: true,
                        hideAfter: false,
                        stack: false,
                        position: 'bottom-left',
                        textAlign: 'left',
                        loader: true,
                        loaderBg: '#9EC600',
                        beforeShow: function () {
                        },
                        afterShown: function () {
                        },
                        beforeHide: function () {
                        },
                        afterHidden: function () {
                        }
                    });//toast

                }//result.sucess
                else{
                    $.toast({
                        text: "There was an error approving this video",
                        heading: "Approvals",
                        icon: "error",
                        showHideTransition: 'fade',
                        allowToastClose: true,
                        hideAfter: false,
                        stack: false,
                        position: 'bottom-left',
                        textAlign: 'left',
                        loader: true,
                        loaderBg: '#9EC600',
                        beforeShow: function () {
                        },
                        afterShown: function () {
                        },
                        beforeHide: function () {
                        },
                        afterHidden: function () {
                        }
                    });//toast
                }
            },"json");
        });//.approve

        $(".reject").click(function(){
            $.toast({
                text :"Rejecting Video",
                hideAfter: 3000
            });
            jQuery.post("index.php", {
                action: "reject",
                video_id: $(this).attr("video_id")
            }, function (data) {
                result = data;

                if(result.success){
                    //remove video

                    $.toast({
                        text: "Video has been Rejected",
                        heading: "Rejection",
                        icon: "info",
                        showHideTransition: 'fade',
                        allowToastClose: true,
                        hideAfter: false,
                        stack: false,
                        position: 'bottom-left',
                        textAlign: 'left',
                        loader: true,
                        loaderBg: '#9EC600',
                        beforeShow: function () {
                        },
                        afterShown: function () {
                        },
                        beforeHide: function () {
                        },
                        afterHidden: function () {
                        }
                    });//toast
                    nextVideo();
                    $(".vid-"+result.vid).remove();
                }//result.sucess
                else{
                    $.toast({
                        text: "There was an error rejecting this video",
                        heading: "Rejection",
                        icon: "error",
                        showHideTransition: 'fade',
                        allowToastClose: true,
                        hideAfter: false,
                        stack: false,
                        position: 'bottom-left',
                        textAlign: 'left',
                        loader: true,
                        loaderBg: '#9EC600',
                        beforeShow: function () {
                        },
                        afterShown: function () {
                        },
                        beforeHide: function () {
                        },
                        afterHidden: function () {
                        }
                    });//toast
                }
            },"json");
        });//.approve

        $(".submit-share").click(function(){

            var form = document.getElementById("share-video-form");
            var fd = new FormData(form);
            fd.append('region', $('select.regions').val());
            fd.append('youtube_id', youtube_parser($('input[name=youtube_link]').val()));
            fd.append('upload', "true");
            $.toast({
                text :"Sharing Video, this might take a few seconds ...",
                hideAfter: 3000
            });
            $.ajax({
                url: "index.php",  //server script to process data
                type: 'POST',
                data: fd,
                cache: false,
                contentType: false,
                processData: false,
                dataType: 'json',
                complete: function(data){
                    console.log(data);
                    result = data.responseJSON;
                    site_url = '<?php echo SITE_URL; ?>';
                   if(result.success == "true"){
                       $.toast({
                           text: result.message + "<a href='"+site_url+"?v="+result.youtube_id+"'></a>",
                           heading: "Check Your Email (Don't forget your Spam folder)",
                           icon: "info",
                           showHideTransition: 'fade',
                           allowToastClose: true,
                           hideAfter: false,
                           stack: false,
                           position: 'bottom-left',
                           textAlign: 'left',
                           loader: true,
                           loaderBg: '#9EC600',
                           beforeShow: function () {
                           },
                           afterShown: function () {
                           },
                           beforeHide: function () {
                           },
                           afterHidden: function () {
                           }
                       });//toast
                   }
                   else{
                       $.toast({
                           text: result.message,
                           heading: "There was a problem sharing the video",
                           icon: "info",
                           showHideTransition: 'fade',
                           allowToastClose: true,
                           hideAfter: false,
                           stack: false,
                           position: 'bottom-left',
                           textAlign: 'left',
                           loader: true,
                           loaderBg: '#9EC600',
                           beforeShow: function () {
                           },
                           afterShown: function () {
                           },
                           beforeHide: function () {
                           },
                           afterHidden: function () {
                           }
                       });//toast
                   }

                }
            },'json');
        });


    });
</script>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.0/css/bootstrap.min.css" integrity="sha384-9aIt2nRpC12Uk9gS9baDl411NQApFmC26EwAOH8WgZl5MYYxFfc+NcPb1dKGj7Sk" crossorigin="anonymous">
    <link rel="stylesheet" type="text/css" href="slick/slick.css"/>
    <link rel="stylesheet" type="text/css" href="slick/slick-theme.css"/>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-beta.1/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-beta.1/dist/js/select2.min.js"></script>
    <script src="https://apis.google.com/js/platform.js"></script>


    <meta name="google-signin-client_id" content="<?php echo GOOGLE_CLIENT_ID; ?>">
    <script async src="https://w.appzi.io/bootstrap/bundle.js?token=uL8yF"></script>
</head>
<body>

<div class="container">
    <a style="font-weight:bold;text-align: center;
    margin-left: auto;
    margin-right: auto;
   display: block;
    background-color: white;
    margin-top: 20px;" href="mailto:pauljuniorgeorge@gmail.com?subject=Request%20to%20Contact%20Poster&body=I%20would%20like%20to%20contact%20the%20poster%20of%20this%20video%0D%0A%0D%0A<?php echo urlencode($_SERVER["HTTP_HOST"].$_SERVER["REQUEST_URI"]); ?>">Are you a Lawyer or Member of an Accredited Organization looking to contact the poster ("<?php echo $currentVideo->username?>") of this Video? </a>
    <div class="jumbotron mt-3" style="background:white;padding:10px;">

       <?php
        if(empty($currentVideo->youtube_id)){
            ?>
            <!-- 1. The <iframe> (and video player) will replace this <div> tag. -->
            <div id="player" width="100%" height="75%" style="width:100%;height:75%;display:none;"></div>
            <video id="otherVideo" width="100%" height="75%" style="width:100%;height:75%;" controls autoplay>
                <source src="<?php echo $currentVideo->source; ?> >" type="video/mp4">
            </video>
            <script>stopVideo();</script>
        <?php
        } else{
       ?>
            <!-- 1. The <iframe> (and video player) will replace this <div> tag. -->
            <div id="player" width="100%" height="75%" style="width:100%;height:75%;"></div>
            <video id="otherVideo" width="100%" height="75%" style="width:100%;height:75%;display:none;" controls autoplay>
                Your browser does not support the video tag.
            </video>

       <?php } ?>

        <script>

            // 2. This code loads the IFrame Player API code asynchronously.
            var tag = document.createElement('script');

            tag.src = "https://www.youtube.com/iframe_api";
            var firstScriptTag = document.getElementsByTagName('script')[0];
            firstScriptTag.parentNode.insertBefore(tag, firstScriptTag);

            // 3. This function creates an <iframe> (and YouTube player)
            //    after the API code downloads.
            var player;

            document.getElementById('otherVideo').addEventListener('ended',finishedOtherVideo,false);

            gapi.load('auth2', function() {
                gapi.auth2.init();
            });

            function signOut() {
                var auth2 = gapi.auth2.getAuthInstance();
                auth2.signOut().then(function () {
                    console.log('User signed out.');
                });
                    window.location = "/logout.php";
            }

            function onYouTubeIframeAPIReady() {
                window.player = new YT.Player('player', {
                    height: '75%',
                    width: '100%',
                    videoId: '<?php echo empty($currentVideo->youtube_id)? 'SKRWOeqRWZM' : $currentVideo->youtube_id ?>',
                    events: {
                        'onReady': onPlayerReady,
                        'onStateChange': onPlayerStateChange
                    }
                });

            }

            // 4. The API will call this function when the video player is ready.
            function onPlayerReady(event) {
                event.target.playVideo();

                <?php
                if(!empty($currentVideo)){

                    if(!empty($currentVideo->youtube_id)){
                        echo "playVideo('".$currentVideo->youtube_id."');";
                    }
                    else{
                        echo "playOtherVideo('".$currentVideo->source."');";
                    }


                }
                ?>
            }

            // 5. The API calls this function when the player's state changes.
            //    The function indicates that when playing a video (state=1),
            //    the player should play for six seconds and then stop.

            function onPlayerStateChange(event) {
                if (event.data == 0) {
                    //setTimeout(stopVideo, 6000);
                    nextVideo();
                }
            }

            function finishedOtherVideo(e) {
                // What you want to do after the event
                nextVideo();
            }

            function stopVideo() {
                window.player.stopVideo();
            }
            function stopOtherVideo(){
                $('#otherVideo')[0].pause();
                $('#otherVideo')[0].src = " ";
            }

            function playVideo(youtube_id){
                window.currentVideo = $("[youtube_id='"+youtube_id+"'").attr("video_id");
                stopOtherVideo();
                $("#otherVideo").hide();
                $("#player").show();
                player.loadVideoById(youtube_id, 0);
            }

            function playOtherVideo(source){
                window.currentVideo = $("[source='"+source+"'").attr("video_id");
                $("#otherVideo").attr("src",source);
                $("#otherVideo").show();
                $("#player").hide();
                stopVideo();

            }

            function nextVideo(){

                window.location = "<?php echo SITE_URL ?>?v="+$("a[video_id="+window.currentVideo+"]").next().attr("video_id")+"&r=<?php echo $_GET["r"] ?>";


                /*  if($("a[video_id="+window.currentVideo+"]").next().length > 0){
                     youtube_id = $("a[video_id="+window.currentVideo+"]").next().attr("youtube_id");
                 }
                 else{
                     youtube_id = $("a[video_id="+window.currentVideo+"]").attr("youtube_id");
                 }

  if(youtube_id.length <1){


                   $("#otherVideo").show();
                     playOtherVideo($("a[video_id="+window.currentVideo+"]").next().attr("source"));
                     $("#player").hide();
                     stopVideo();
                 }
                 else{
                     $("#otherVideo").hide();
                     $("#player").show();
                     playVideo(youtube_id);
                     stopOtherVideo();
                 }

                     */
            }


            function updateUser(result) {

                var icon = (result.success == "true")? "info": "error";
                $.toast({
                    text: result.message,
                    heading: "Request Complete",
                    icon: icon,
                    showHideTransition: 'fade',
                    allowToastClose: true,
                    hideAfter: false,
                    stack: false,
                    position: 'bottom-left',
                    textAlign: 'left',
                    loader: true,
                    loaderBg: '#9EC600',
                    beforeShow: function () {
                    },
                    afterShown: function () {
                    },
                    beforeHide: function () {
                    },
                    afterHidden: function () {
                    }
                });//toast
            }
            $(document).ready(function(){
                $('[data-toggle="popover"]').popover({
                    placement : 'top',
                    trigger : 'hover'
                });
            });
            $(document).ready(function(){
                $('.your-class').slick({
                    dots: true,
                    infinite: false,
                    speed: 300,
                    slidesToShow: 4,
                    slidesToScroll: 4});

                //determine specific video to play

            });

            $(document).ready(function(){
                $('.regions_select').change(function(){

                    if(window.location.href.indexOf("&r=") > -1){
                        newLocation = window.location.href.substring(0,(window.location.href.indexOf("&r=")+3))
                        newLocation = newLocation + $(this).val();
                    }
                    else{
                        newLocation = window.location.href+"&r="+$(this).val()
                    }

                    window.location = newLocation;
                });
            });

            <?php



            ?>

        </script>


    </div>
    <span>Select a Region</span>
    <select class="regions_select" name="region" style="width:250px;">
        <option value="0">Everywhere (WorldWide)</option>
        <?php
        foreach($regions as $region){
            echo '<option '.((isset($_GET['r']) && $_GET['r'] == $region->region_id)? "selected": "").' value="'.$region->region_id.'" >'.$region->name.'('.$region->video_count.')</option>';
        }
        ?>
    </select>

    <br>
    <div class="your-class" style="height:225px;margin-top:10px;">
        <?php

        if(!empty($pendingApproval)) {
            foreach ($pendingApproval as $video) {
                echo "<a href='" . SITE_URL . "?v=" . $video->id . "' video_id='" . $video->id . "' source='" . $video->source . "' youtube_id='" . $video->youtube_id . "'  class='playvideo vid-" . $video->id . "' style='position:relative;cursor:pointer;'><img style='width:300px;height:225px;' src='" . $video->thumbnail . "' /><span class='approve' video_id='" . $video->id . "' style='padding: 5px;
    color: white;
    bottom: 5px;
    left: 5px;
    position: absolute;
    background: green;
    cursor: grabbing;font-weight:bold;'>Approve</span>
    
    <span video_id='" . $video->id . "' class='reject' style='padding: 5px;
    color: white;
    bottom: 5px;
    right: 5px;
    position: absolute;
    background: red;
    cursor: not-allowed;font-weight:bold;' >Reject</span>
    <span style='width:100%;padding: 5px;
    color: white;
    top: 0;
    left: 0;
    opacity: 75%;
    position: absolute;
    background: black;
    cursor: pointer;font-size:11px;'>" . $video->title . " (" . $video->created . ")</span>
    </a>";
            }
        }

if(count($approvedVideos) > 0){
    foreach($approvedVideos as $video){
        echo "<a href='".SITE_URL."?v=".$video->id."&r=".(isset($_GET['r'])? $_GET['r'] : 0)."' video_id='".$video->id."' source='".$video->source."' youtube_id='".$video->youtube_id."' class='playvideo vid-".$video->id."' style='position:relative;cursor:pointer;'><img style='width:300px;height:225px;' src='".$video->thumbnail."' />
    <span style='width:100%;padding: 5px;
    color: white;
    top: 0;
    left: 0;
    opacity: 75%;
    position: absolute;
    background: black;
    cursor: pointer;font-size:11px;'>".$video->title." (".$video->created.")</span>
    
    <span style='width:100%;padding: 5px;
    color: white;
    bottom: 0;
    left: 0;
    opacity: 75%;
    position: absolute;
    background: black;
    cursor: pointer;font-size:11px;'>".$video->username." (".$video->name.")</span>
    
    </a>";
    }
}
     else{
         echo "<span style='display:block;margin-left:auto;margin-right:auto;font-weight:bold;'>No Content Found for this region, be the first to share your experience</span>";
     }

        ?>

    </div>
    <script type="text/javascript">
        amzn_assoc_placement = "adunit0";
        amzn_assoc_tracking_id = "policecrimest-20";
        amzn_assoc_ad_mode = "search";
        amzn_assoc_ad_type = "smart";
        amzn_assoc_marketplace = "amazon";
        amzn_assoc_region = "US";
        amzn_assoc_default_search_phrase = "dash cams";
        amzn_assoc_default_category = "All";
        amzn_assoc_linkid = "fd3fdf328b91845405481871091b6b06";
        amzn_assoc_design = "in_content";
    </script>
    <script src="//z-na.amazon-adsystem.com/widgets/onejs?MarketPlace=US"></script>
<hr>
    <div id="replybox"></div>
    <hr>
    <script>
        window.replybox = {
            site: 'rbGnNyqY9v',
            identifier: '<?php echo $_GET["v"]; ?>',
        };
    </script>

    <script src="https://cdn.getreplybox.com/js/embed.js"></script>
    <script type="text/javascript">
        amzn_assoc_placement = "adunit0";
        amzn_assoc_search_bar = "true";
        amzn_assoc_tracking_id = "policecrimest-20";
        amzn_assoc_ad_mode = "manual";
        amzn_assoc_ad_type = "smart";
        amzn_assoc_marketplace = "amazon";
        amzn_assoc_region = "US";
        amzn_assoc_title = "What Better time to Consider a Body Camera";
        amzn_assoc_linkid = "6b81f38cd10de822054ad5643d7491eb";
        amzn_assoc_asins = "B085HQ8NY3,B082V9246B,B07X1YLC2L,B088897H5Z";
    </script>
    <script src="//z-na.amazon-adsystem.com/widgets/onejs?MarketPlace=US"></script>

</div>

<nav class="navbar fixed-bottom navbar-expand-sm navbar-dark bg-dark">
    <?php
    if(is_user()){
        echo "<img onclick='signOut();' data-toggle='popover' data-content='Logout?' style='cursor:pointer;border-radius: 50%;height: 35px;' src='".$_SESSION['user_thumb']."' />";
    }
    else{
        echo '<div class="g-signin2" data-toggle="popover" data-content="Sign In to Subscribe to and Share new content" data-onsuccess="onSignIn"></div>';
    }

    ?>

    <a class="navbar-brand" style="margin-left:15px;font-weight: bold;" href="#">PoliceCrimeStoppers</a>
    <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarCollapse" aria-controls="navbarCollapse" aria-expanded="false" aria-label="Toggle navigation">
        <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navbarCollapse">
        <ul class="navbar-nav mr-auto" style="font-weight: bold;">
            <li class="nav-item">

            </li>
            <li class="nav-item share-video">
                <a class="nav-link" href="#" data-toggle="popover" data-content="Were you or someone you know the victim of police brutality or other unlawful acts by the Police. Share your experience here with (<?php echo SUBSCRIBER_COUNT ;?>) of our followers">Share a Video </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="#" data-toggle='popover' data-content='This sites purpose is to make it easy to share content of Police misconduct and crime with a large audience and not have to rely on false and very unlikely hopes of your experience going viral. By sharing an experience you had with the police on the site we immediately tell our entire base of followers (<?php echo SUBSCRIBER_COUNT; ?>) via email. You can in turn get help from lawyers in the community and the community as a whole by sharing your experiences. Our intention is to create a thriving community of people dedicated to putting a stop to police misconduct working together around the world.'>About this Site</a>
            </li>

            <li class="nav-item ml-auto" style="    right: 15px;
    position: absolute;
    font-weight: bold;
    color: white;
    margin-top: 10px;
    font-style: italic;">
                <span>Sign in to Follow. (<?php echo SUBSCRIBER_COUNT ?>) Followers.</span>
            </li>



        </ul>
    </div>
</nav>
<script type="text/javascript" src="//cdn.jsdelivr.net/npm/slick-carousel@1.8.1/slick/slick.min.js"></script>

<div class="modal fade" id="share-video-dialog" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title">Share Video with <?php echo SUBSCRIBER_COUNT; ?> followers</h6>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            </div>

            <div class="modal-body">
               <form action="index.php" id="share-video-form" method="post">
                   <span>(Recommended)</span>
<br>
                   <input type="text" name="youtube_link" class="form-control" placeholder="Share a YouTube Link" aria-describedby="basic-addon1">
                   <br>
               <span style="margin-left:auto;margin-right:auto;width:100px;display: block;
    text-align: center;font-weight:bold;">OR</span>

                <br>
                <input name="videofile" type="file" />
                  <br> <span>Uploading a new video may take awhile</span>
                <hr>
                What Region did this offence take place?
                <select class="regions" name="region" style="width:200px;">
                    <?php
                    foreach($regions as $region){
                        echo '<option value="'.$region->id.'" >'.$region->name.'</option>';
                    }
                    ?>
                </select>
               </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary submit-share">Share Video</button>
            </div>
        </div><!-- /.modal-content -->
    </div><!-- /.modal-dialog -->
</div><!-- /.modal -->

</body>
</html>

