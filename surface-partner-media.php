<?php
/**
 * Plugin Name: Surface Partner Media
 * Description: Image stamp and media tools.
 * Version: 1.0.1
 */

if(!defined('ABSPATH')) exit;

class SurfacePartnerMedia {

static function init(){

add_shortcode('surface_media',[__CLASS__,'media']);

}

static function media(){

if(!is_user_logged_in()) return;

$user=get_current_user_id();
$surface_name = SurfacePartnerCore::current_surface_identity();

$products = get_posts([
'post_type'      => ['product','surface_signal'],
'post_status'    => 'any',
'posts_per_page' => 100,
'meta_query'     => [
        [
                'key'   => '_surface_partner_user_id',
                'value' => $user
        ]
]
]);

ob_start();

$surface_dashboard_url = home_url('/surface-internet-registry/');

echo '<div style="width:100%;max-width:900px;margin:0 auto 18px;box-sizing:border-box;">';
echo '<a href="' . esc_url($surface_dashboard_url) . '" style="
    display:inline-flex;
    align-items:center;
    gap:6px;
    padding:9px 13px;
    background:#f3f4f6;
    color:#111827;
    border:1px solid #d1d5db;
    border-radius:8px;
    text-decoration:none;
    font-size:14px;
    font-weight:700;
    box-sizing:border-box;
">← Dashboard</a>';
echo '</div>';

if (isset($_GET['surface_audio_stamp']) && isset($_GET['node'])) {
    $node_id = intval($_GET['node']);

    if (class_exists('Surface_Ultrasound')) {
        $product = function_exists('wc_get_product') ? wc_get_product($node_id) : null;

        if ($product) {
            $sku = $product->get_sku();
        } else {
            $sku = get_post_meta($node_id, '_surface_kxcode', true);
        }

        $token = $sku;
        $result = Surface_Ultrasound::generate_wav_for_token($token, $node_id);

        if (!is_wp_error($result)) {
            // Track audio bandwidth usage
if (class_exists('SurfaceInfrastructure') && !empty($result['path'])) {
    $file_path = $result['path'];
    if (file_exists($file_path)) {
        $file_size = filesize($file_path);
        SurfaceInfrastructure::consume_audio(get_current_user_id(), $file_size);
    }
}
    echo '<div style="border:1px solid #ddd;border-radius:10px;padding:16px;margin-bottom:16px;background:#fff;">';
    echo '<div style="font-size:12px;font-weight:700;color:#888;margin-bottom:6px;">SURFACE AUDIO</div>';
    echo '<div style="font-size:16px;font-weight:700;margin-bottom:12px;">Surface Audio Ready</div>';
    echo '<a href="' . esc_url($result['url']) . '" download style="display:inline-block;padding:12px 16px;background:#111;color:#fff;border-radius:8px;text-decoration:none;font-weight:600;">Download Audio</a>';
    echo '</div>';
    return;
}
        else {
            echo '<div style="background:#fdeaea;padding:12px;border-radius:8px;margin-bottom:12px;font-weight:600;">Audio generation failed</div>';
        }
    }
}


if(isset($_POST['surface_stamp_product']) && !empty($_POST['surface_media_product'])){

$product_id = (int) $_POST['surface_media_product'];
$selected_product_id = isset($_GET['product']) ? (int) $_GET['product'] : $product_id;
$post_type = get_post_type($product_id);
$code_for_file = ($post_type === 'surface_signal')
    ? get_post_meta($product_id, '_surface_kxcode', true)
    : get_post_meta($product_id, '_sku', true);

$download_name = $surface_name . '-' . $code_for_file . '.png';
$post_type = get_post_type($product_id);

$image_id = 0;
$image_url = '';
if ($post_type === 'surface_signal') {
    $image_url = (string) get_post_meta($product_id, '_surface_signal_image_url', true);
    $video_url = (string) get_post_meta($product_id, '_surface_signal_video_url', true);
    $audio_url = (string) get_post_meta($product_id, '_surface_signal_audio_url', true);
} else {
    $image_url = (string) get_post_meta($product_id, '_surface_market_image_url', true);
     //echo'<div style="background:yellow;color:black;padding:10px;word-break:break-all;">';
//echo esc_html($image_url);
//echo '</div>';
    $video_url = (string) get_post_meta($product_id, '_surface_market_video_url', true);
    $audio_url = (string) get_post_meta($product_id, '_surface_market_audio_url', true);
    
}
echo '<div style="background:#000;color:#0f0;padding:10px;margin-bottom:10px;">';
//echo 'POST TYPE: ' . esc_html($post_type) . '<br>';
//echo 'PRODUCT ID: ' . esc_html($product_id) . '<br>';


echo '</div>';
if($image_url){

// SHOW VIDEO IF EXISTS
if ($audio_url) {

   $ready_url = ($post_type === 'surface_signal')
    ? get_post_meta($product_id, '_surface_signal_audio_ready_url', true)
    : get_post_meta($product_id, '_surface_market_audio_ready_url', true);

    echo '<div style="margin-top:12px;position:relative;max-width:420px;">';
echo '<div style="font-weight:700;margin-bottom:6px;">Surface Audio</div>';
    echo '<audio controls style="width:100%;max-width:420px;display:block;">';
    echo '<source src="' . esc_url($audio_url) . '">';
    echo '</audio>';

if ($ready_url) {
    echo '<a href="' . esc_url($ready_url) . '" download="surface-audio.wav" style="position:absolute;top:12px;right:12px;background:rgba(0,0,0,0.6);color:#fff;width:38px;height:38px;display:flex;align-items:center;justify-content:center;border-radius:999px;text-decoration:none;font-size:18px;">⬇</a>';
}

   if ($ready_url) {
    echo '<div style="background:#ecfdf5;color:#065f46;padding:8px 10px;border-radius:8px;font-size:12px;font-weight:600;display:inline-block;margin-top:8px;">Surface Audio Ready</div>';
}

    echo '</div>';
}

if ($video_url) {
    $ready_video_url = ($post_type === 'surface_signal')
    ? get_post_meta($product_id, '_surface_signal_video_ready_url', true)
    : get_post_meta($product_id, '_surface_market_video_ready_url', true);
echo '<div style="font-weight:700;margin-bottom:6px;">Surface Video</div>';

echo '<div style="position:relative;max-width:420px;">';

echo '<video controls style="width:100%;border-radius:10px;display:block;">
        <source src="' . esc_url($video_url) . '" type="video/mp4">
      </video>';

if ($ready_video_url) {

echo '<a href="' . esc_url($ready_video_url) . '" download
        style="position:absolute;top:12px;right:12px;background:rgba(0,0,0,0.6);
        color:#fff;width:38px;height:38px;display:flex;align-items:center;
        justify-content:center;border-radius:999px;text-decoration:none;font-size:18px;">
        ⬇
      </a>';

echo '<div style="background:#ecfdf5;color:#065f46;padding:8px 10px;border-radius:8px;font-size:12px;font-weight:600;display:inline-block;margin-top:8px;">Surfaced Video Ready</div>';

}

echo '</div>'; // video wrapper
echo '</div>'; // outer container



}
$post_type = get_post_type($product_id);

if ($post_type === 'surface_signal') {
    $signal_target = (string) get_post_meta($product_id, '_surface_signal_target', true);
    $signal_suffix = (string) get_post_meta($product_id, '_surface_signal_suffix', true);

    $target_clean = strtolower(trim($signal_target));
    $target_clean = ltrim($target_clean, "/#@\\");
    $target_clean = preg_replace('/[^a-z0-9]/', '', $target_clean);

    $suffix_clean = strtolower(trim($signal_suffix));
    $suffix_clean = preg_replace('/[^a-z0-9]/', '', $suffix_clean);

    $sku = $target_clean . $suffix_clean;
} else {
    $sku = get_post_meta($product_id, '_sku', true);
}

//echo "<h3>Stamp Preview</h3>";
echo "<p><strong>/$sku</strong></p>";
echo "<div id='surfaceCaptionData' data-surface='" . esc_attr($surface_name) . "' data-sku='" . esc_attr($sku) . "'></div>";


echo '</div>';
echo '<div id="surfaceStampPreview" style="position:relative;display:inline-block;max-width:420px;">';
echo "<img src='" . esc_url($image_url) . "' style='max-width:100%;height:auto;display:block;object-fit:contain;background:#000;border-radius:10px;'>";
echo '<a href="#" id="downloadStampedImage" data-filename="' . esc_attr($download_name) . '" onclick="event.preventDefault();var btn=this;var target=document.getElementById(\'surfaceStampPreview\');if(!target){alert(\'Preview not found\');return false;}var oldDisplay=btn.style.display;btn.style.display=\'none\';html2canvas(target,{scale:2,useCORS:true,allowTaint:true,backgroundColor:null}).then(function(canvas){btn.style.display=oldDisplay;var link=document.createElement(\'a\');link.download=\'' . esc_attr($download_name) . '\';link.href=canvas.toDataURL(\'image/png\');document.body.appendChild(link);link.click();document.body.removeChild(link);}).catch(function(){btn.style.display=oldDisplay;alert(\'Download failed\');});return false;" style="position:absolute;top:12px;right:12px;background:rgba(0,0,0,0.45);color:#fff;width:38px;height:38px;display:flex;align-items:center;justify-content:center;border-radius:999px;text-decoration:none;font-size:18px;">⬇</a>';
echo '<div style="position:absolute;bottom:0;left:0;display:flex;flex-direction:column;gap:2px;align-items:flex-start;max-width:78%;">
  
  <span style="margin:0 0 0 0;background:rgba(0,0,0,0.55);color:#fff;padding:6px 10px;border-radius:6px;font-weight:700;font-size:13px;line-height:1.05;letter-spacing:0.2px;display:inline-block;">@' . esc_html($sku) . '</span>
</div>';

echo "</div>";

echo "<div style='margin-top:12px;display:grid;grid-template-columns:1fr 1fr;gap:10px;max-width:420px;'>



</div>";

}
else{
echo "<p style='color:red;'>This selected product has no featured image to stamp.</p>";
}

}

if(isset($_GET['surface_audio_stamp']) && isset($_GET['node'])){

    $node_id = intval($_GET['node']);

    if(class_exists('Surface_Ultrasound')){

        $product = function_exists('wc_get_product') ? wc_get_product($node_id) : null;

        if($product){
            $sku = $product->get_sku();
        }else{
            $sku = get_post_meta($node_id,'_surface_kxcode',true);
        }

        $token = $sku;

        $result = Surface_Ultrasound::generate_wav_for_token($token,$node_id);

        if(!is_wp_error($result)){

            $target = remove_query_arg(['surface_audio_stamp','audio_preview','node']);
$target = add_query_arg([
    'audio_preview' => 1,
    'node' => $node_id
], $target);

wp_safe_redirect($target);
exit;

            echo '<a href="'.$result['url'].'" style="display:inline-block;padding:10px 14px;background:#111;color:#fff;border-radius:6px;text-decoration:none;font-weight:600;">Download Audio</a>';

        }else{

            echo '<div style="background:#fdeaea;padding:12px;border-radius:8px;margin-bottom:12px;font-weight:600;">Audio generation failed</div>';

        }

    }

}

if(isset($_GET['audio_preview']) && isset($_GET['node'])){

    $node_id = intval($_GET['node']);
    $node_post = get_post($node_id);
    $post_type = get_post_type($node_id);

    if($post_type === 'surface_signal'){
        $kxcode = get_post_meta($node_id, '_surface_kxcode', true);
        $node_type = 'SIGNAL NODE';
    }else{
        $product = function_exists('wc_get_product') ? wc_get_product($node_id) : null;
        $kxcode = $product ? $product->get_sku() : '';
        $node_type = 'MARKET NODE';
    }

    $upload_dir = wp_upload_dir();
    $filename = 'surface-ultra-' . (int)$node_id . '-' . sanitize_title($kxcode) . '.wav';
    $file_url = trailingslashit($upload_dir['baseurl']) . 'surface-ultrasound/' . $filename;

    echo '<div style="border:1px solid #ddd;border-radius:10px;padding:16px;margin-bottom:16px;background:#fff;">';
    echo '<div style="font-size:12px;font-weight:700;color:#888;margin-bottom:6px;">' . esc_html($node_type) . '</div>';
    echo '<div style="font-size:16px;font-weight:700;margin-bottom:6px;">' . esc_html($node_post ? $node_post->post_title : 'Untitled') . '</div>';
    echo '<div style="font-size:13px;color:#666;margin-bottom:12px;">' . esc_html($kxcode) . '</div>';
    echo '<div style="background:#e8f7ee;padding:10px;border-radius:6px;margin-bottom:12px;font-weight:600;">Surface Audio Ready</div>';
    echo '<a href="' . esc_url($file_url) . '" download style="display:inline-block;padding:12px 16px;background:#111;color:#fff;border-radius:8px;text-decoration:none;font-weight:600;">Download Audio</a>';
    echo '</div>';
}
//echo "<h3>Stamp Across Surfaces</h3>";

//echo '<p>Select a Market Node or Signal Node and stamp internet abilities across surfaces.</p>';
echo '<div id="surfaceNodeThumbs" style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;max-width:420px;margin:12px 0 16px;">';

foreach($products as $p){

    $post_type = get_post_type($p->ID);

   if ($post_type === 'surface_signal') {
    $thumb = (string) get_post_meta($p->ID, '_surface_signal_image_url', true);
} else {
    $thumb = (string) get_post_meta($p->ID, '_surface_market_image_url', true);
} 

    $post_type = get_post_type($p->ID);

if ($post_type === 'surface_signal') {
    $signal_target = (string) get_post_meta($p->ID, '_surface_signal_target', true);
    $signal_suffix = (string) get_post_meta($p->ID, '_surface_signal_suffix', true);

    $target_clean = strtolower(trim($signal_target));
    $target_clean = ltrim($target_clean, "/#@\\");
    $target_clean = preg_replace('/[^a-z0-9]/', '', $target_clean);

    $suffix_clean = strtolower(trim($signal_suffix));
    $suffix_clean = preg_replace('/[^a-z0-9]/', '', $suffix_clean);

    $sku = $target_clean . $suffix_clean;
} else {
    $sku = get_post_meta($p->ID, '_sku', true);
}
$node_type = ($post_type === 'surface_signal') ? 'BROADCAST NODE' : 'MARKET NODE';
    echo '<form method="post" style="margin:0;">';

    echo '<input type="hidden" name="surface_media_product" value="'.(int)$p->ID.'">';
    echo '<input type="hidden" name="surface_stamp_product" value="1">';

    echo '<div style="border:1px solid #ddd;background:#fff;border-radius:10px;padding:8px;text-align:left;">';

    $thumb_src = $thumb . (strpos($thumb, '?') === false ? '?' : '&') . 'v=' . (int) $p->ID;
echo '<img src="' . esc_url($thumb_src) . '" referrerpolicy="no-referrer" crossorigin="anonymous" style="width:100%;aspect-ratio:1/1;object-fit:cover;border-radius:8px;display:block;margin-bottom:8px;">';

    echo '<div style="font-size:10px;font-weight:700;color:#888;letter-spacing:1px;">'.$node_type.'</div>';
echo '<div style="font-weight:600;font-size:14px;">'.esc_html($p->post_title).'</div>';
   echo '<div style="font-size:12px;color:#666;margin-top:4px;">@' . esc_html($sku) . '</div>';

echo '<div style="margin-top:10px;">';

echo '<button type="submit" style="display:block;width:100%;padding:14px 16px;background:#1e73be;color:#fff;border:0;border-radius:10px;font-weight:700;box-sizing:border-box;">Switch On Surface</button>';



echo '</div>';

    echo '</div>';
    echo '</form>';
//}

echo '</div>';
//echo '<form id="surfaceMediaForm" method="post">';
//echo '<p><select id="surface_media_product" name="surface_media_product" style="width:100%;max-width:420px;padding:10px;">';
//echo '<option value="">Select Market Node</option>';

//foreach($products as $p){
   // $sku = get_post_meta($p->ID,'_sku',true);
//$selected = (isset($selected_product_id) && $selected_product_id === (int)$p->ID) ? ' selected' : '';
//echo '<option value="' . (int)$p->ID . '"' . $selected . '>' . esc_html($p->post_title . " — " . $sku) . '</option>';
}

//echo '</select></p>';
//echo '<p><button id="surfaceStampButton" type="submit" name="surface_stamp_product" value="1">Stamp Selected Market Node Image</button></p>';
//echo '</form>';


echo "<script src='https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js'></script>";

echo "<script>

document.addEventListener('DOMContentLoaded', function(){

  const stampedImageBtn = document.getElementById('downloadStampedImage');
const captionBtn = document.getElementById('copyCaption');
const statusBtn = document.getElementById('downloadStatus');
const instagramBtn = document.getElementById('downloadInstagram');
const socialBtn = document.getElementById('downloadSocial');
  function downloadImage(filename, width, height){
    const target = document.querySelector('#surfaceStampPreview');

    if (!target) {
      alert('Preview not found');
      return;
    }

    html2canvas(target, { scale: 2 }).then(function(canvas){

      var resizedCanvas = document.createElement('canvas');
      var ctx = resizedCanvas.getContext('2d');

      resizedCanvas.width = width;
      resizedCanvas.height = height;

      ctx.drawImage(canvas, 0, 0, width, height);

      var link = document.createElement('a');
      link.download = filename;
      link.href = resizedCanvas.toDataURL('image/png');

      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);

    }).catch(function(err){
      console.log(err);
      alert('Download failed');
    });
  }

  document.addEventListener('click', function(e) {
  alert('Surface Media JS loaded');
  var stampedImageBtn = e.target.closest('#downloadStampedImage');
  if (!stampedImageBtn) return;

  e.preventDefault();
  

  var filename = stampedImageBtn.getAttribute('data-filename') || 'surface-stamped.png';
  downloadImage(filename, 1080, 1080);
});


if(captionBtn){
captionBtn.addEventListener('click', function(){

var capNode = document.getElementById('surfaceCaptionData');
if(!capNode) return;

var surface = capNode.getAttribute('data-surface');
var sku = capNode.getAttribute('data-sku');

var caption =
'#' + surface + '\n' +
'#' + sku + '\n\n' +
'KXCODE: ' + sku;

navigator.clipboard.writeText(caption);
alert('Caption copied');

});
}
if(statusBtn){
statusBtn.addEventListener('click',function(e){
e.preventDefault();
downloadImage('whatsapp-status.png',1080,1920);
});
}

if(instagramBtn){
instagramBtn.addEventListener('click',function(e){
e.preventDefault();
downloadImage('instagram-post.png',1080,1350);
});
}

if(socialBtn){
socialBtn.addEventListener('click',function(e){
e.preventDefault();
downloadImage('twitter-facebook.png',1200,675);
});
}
if(captionBtn){
captionBtn.addEventListener('click', function(){

var surface = document.querySelector('#surfaceCaptionData').dataset.surface;
var sku = document.querySelector('#surfaceCaptionData').dataset.sku;

var caption =
'#' + surface + '\n' +
'#' + sku + '\n\n' +
'KXCODE: ' + sku;

navigator.clipboard.writeText(caption);
alert('Caption copied');

});
}
});
document.querySelectorAll('.surface-node-thumb').forEach(function(btn){

btn.addEventListener('click',function(e){

e.preventDefault();
e.stopPropagation();

var productId = this.getAttribute('data-product-id');
var dropdown = document.getElementById('surface_media_product');

if(dropdown){
dropdown.value = productId;
dropdown.dispatchEvent(new Event('change'));
}
var stampBtn = document.getElementById('surfaceStampButton');
if(stampBtn){
stampBtn.click();
}
document.querySelectorAll('.surface-node-thumb').forEach(function(b){
b.style.border='1px solid #ddd';
b.style.boxShadow='none';
b.style.background='#fff';
});

this.style.border='2px solid #1e73be';
this.style.boxShadow='0 0 0 2px rgba(30,115,190,0.2)';
this.style.background='#eef6ff';

});
document.addEventListener('click', function(e) {
  var btn = e.target.closest('#downloadStampedImage');
  if (!btn) return;

  e.preventDefault();

  var target = document.getElementById('surfaceStampPreview');
  if (!target) {
    alert('Preview not found');
    return;
  }

  html2canvas(target).then(function(canvas) {
    var link = document.createElement('a');
    link.download = 'surface-stamped.png';
    link.href = canvas.toDataURL('image/png');
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
  }).catch(function() {
    alert('Download failed');
  });
});
});
</script>";
return ob_get_clean();

}
public static function bandwidth_limit_bytes($user_id = 0){
    $user_id = $user_id ?: get_current_user_id();
    if(!$user_id) return 10 * 1024 * 1024;

    $limit = (int) get_user_meta($user_id, '_surface_bandwidth_limit', true);

    if($limit <= 0){
        $limit = 10 * 1024 * 1024; // 10MB default
        update_user_meta($user_id, '_surface_bandwidth_limit', $limit);
    }

    return $limit;
}

public static function bandwidth_used_bytes($user_id = 0){
    $user_id = $user_id ?: get_current_user_id();
    if(!$user_id) return 0;

    return (int) get_user_meta($user_id, '_surface_bandwidth_used', true);
}

public static function bandwidth_remaining_bytes($user_id = 0){
    $limit = self::bandwidth_limit_bytes($user_id);
    $used  = self::bandwidth_used_bytes($user_id);

    return max(0, $limit - $used);
}

public static function can_consume_bandwidth($bytes, $user_id = 0){
    return self::bandwidth_remaining_bytes($user_id) >= (int) $bytes;
}

public static function consume_bandwidth($bytes, $user_id = 0){
    $user_id = $user_id ?: get_current_user_id();
    if(!$user_id) return false;

    $bytes = (int) $bytes;

    if(!self::can_consume_bandwidth($bytes, $user_id)){
        return false;
    }

    $used = self::bandwidth_used_bytes($user_id);
    update_user_meta($user_id, '_surface_bandwidth_used', $used + $bytes);

    return true;
}
}

SurfacePartnerMedia::init();
