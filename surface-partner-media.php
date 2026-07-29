<?php
/**
 * Plugin Name: SurfaceMark
 * Description: Applies the Surface Internet mark to images and videos before download.
 * Version: 1.0.11
 */

if(!defined('ABSPATH')) exit;

class SurfacePartnerMedia {

static function init(){

add_shortcode('surface_media',[__CLASS__,'media']);
add_action('admin_post_surface_download_video',[__CLASS__,'download_video']);

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

$surface_video_notice = '';
$surface_video_error  = '';

if (isset($_POST['surface_stamp_video'], $_POST['surface_video_product'])) {
    $video_product_id = (int) $_POST['surface_video_product'];
    $nonce = isset($_POST['surface_video_nonce']) ? sanitize_text_field(wp_unslash($_POST['surface_video_nonce'])) : '';

    if (!wp_verify_nonce($nonce, 'surface_stamp_video_' . $video_product_id)) {
        $surface_video_error = 'Video request could not be verified.';
    } elseif (!self::user_owns_surface($video_product_id, $user)) {
        $surface_video_error = 'You cannot process this Surface video.';
    } else {
        $video_result = self::stamp_video($video_product_id, $surface_name);

        if (is_wp_error($video_result)) {
            $surface_video_error = $video_result->get_error_message();
        } else {
            $surface_video_notice = 'Surfaced Video Ready';
        }
    }
}

$surface_dashboard_url = home_url('/partner-console/');

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
">← Back to Dashboard</a>';
echo '</div>';

if ($surface_video_notice !== '') {
    echo '<div style="max-width:900px;margin:0 auto 14px;background:#ecfdf5;color:#065f46;padding:10px 12px;border-radius:8px;font-weight:700;box-sizing:border-box;">' . esc_html($surface_video_notice) . '</div>';
}
if ($surface_video_error !== '') {
    echo '<div style="max-width:900px;margin:0 auto 14px;background:#fee2e2;color:#991b1b;padding:10px 12px;border-radius:8px;font-weight:700;box-sizing:border-box;">' . esc_html($surface_video_error) . '</div>';
}

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


$surface_selected_preview = '';
ob_start();

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
if($image_url){

// SHOW VIDEO IF EXISTS
if ($audio_url) {

   $ready_url = ($post_type === 'surface_signal')
    ? get_post_meta($product_id, '_surface_signal_audio_ready_url', true)
    : get_post_meta($product_id, '_surface_market_audio_ready_url', true);

    echo '<div class="surface-mark-media-block surface-mark-audio-block">';
echo '<div class="surface-mark-media-label">Surface Audio</div>';
    echo '<audio controls class="surface-mark-audio-player">';
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
echo '<div class="surface-mark-media-label surface-mark-video-label">Surface Video</div>';

echo '<div class="surface-mark-media-block surface-mark-video-block">';

echo '<video controls class="surface-mark-video-player">
        <source src="' . esc_url($video_url) . '" type="video/mp4">
      </video>';

if ($ready_video_url) {

$video_download_url = wp_nonce_url(
    admin_url('admin-post.php?action=surface_download_video&product_id=' . (int) $product_id),
    'surface_download_video_' . (int) $product_id
);

echo '<a href="' . esc_url($video_download_url) . '"
        style="position:absolute;top:12px;right:12px;background:rgba(0,0,0,0.6);
        color:#fff;width:38px;height:38px;display:flex;align-items:center;
        justify-content:center;border-radius:999px;text-decoration:none;font-size:18px;z-index:20;"
        aria-label="Download surfaced video" title="Download surfaced video">
        ⬇
      </a>';

echo '<form method="post" style="position:absolute;top:12px;right:58px;margin:0;z-index:20;">';
echo '<input type="hidden" name="surface_video_product" value="' . (int) $product_id . '">';
echo '<input type="hidden" name="surface_video_nonce" value="' . esc_attr(wp_create_nonce('surface_stamp_video_' . $product_id)) . '">';
echo '<button type="submit" name="surface_stamp_video" value="1"
        style="border:0;padding:0;background:rgba(0,0,0,0.6);color:#fff;width:38px;height:38px;display:flex;align-items:center;justify-content:center;border-radius:999px;cursor:pointer;font-size:18px;"
        aria-label="Recreate surfaced video" title="Recreate surfaced video">↻</button>';
echo '</form>';

echo '<div style="background:#ecfdf5;color:#065f46;padding:8px 10px;border-radius:8px;font-size:12px;font-weight:600;display:inline-block;margin-top:8px;">Surfaced Video Ready</div>';

} else {
    echo '<form method="post" style="position:absolute;top:12px;right:12px;margin:0;z-index:3;">';
    echo '<input type="hidden" name="surface_video_product" value="' . (int) $product_id . '">';
    echo '<input type="hidden" name="surface_video_nonce" value="' . esc_attr(wp_create_nonce('surface_stamp_video_' . $product_id)) . '">';
    echo '<button type="submit" name="surface_stamp_video" value="1"
        style="border:0;padding:0;background:rgba(0,0,0,0.6);color:#fff;width:38px;height:38px;display:flex;align-items:center;justify-content:center;border-radius:999px;cursor:pointer;font-size:18px;"
        aria-label="Surface and download video" title="Surface and download video">⬇</button>';
    echo '</form>';
}

echo '</div>'; // video wrapper



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

$surface_selected_preview = ob_get_clean();

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
echo '<style>
.surface-mark-library{
    width:100%;
    max-width:1180px;
    margin:22px auto 30px;
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:20px;
    align-items:stretch;
}
.surface-mark-card-form{margin:0;min-width:0;height:100%;}
.surface-mark-card{
    height:100%;
    display:flex;
    flex-direction:column;
    background:#fff;
    border:1px solid #e5e7eb;
    border-radius:18px;
    padding:10px;
    box-shadow:0 8px 24px rgba(15,23,42,.06);
    overflow:hidden;
    box-sizing:border-box;
    transition:transform .18s ease,box-shadow .18s ease,border-color .18s ease;
}
.surface-mark-card:hover{
    transform:translateY(-3px);
    border-color:#cbd5e1;
    box-shadow:0 16px 36px rgba(15,23,42,.11);
}
.surface-mark-thumb{
    width:100%;
    aspect-ratio:1/1;
    overflow:hidden;
    border-radius:13px;
    background:#0f172a;
}
.surface-mark-thumb img{
    width:100%;
    height:100%;
    display:block;
    object-fit:cover;
}
.surface-mark-card-body{
    display:flex;
    flex:1;
    min-height:0;
    flex-direction:column;
    padding:13px 5px 5px;
}
.surface-mark-type{
    min-height:16px;
    color:#64748b;
    font-size:10px;
    line-height:1.3;
    font-weight:800;
    letter-spacing:.11em;
    text-transform:uppercase;
}
.surface-mark-title{
    margin-top:6px;
    color:#0f172a;
    font-size:15px;
    line-height:1.35;
    font-weight:800;
    display:-webkit-box;
    -webkit-line-clamp:2;
    -webkit-box-orient:vertical;
    overflow:hidden;
    min-height:40px;
}
.surface-mark-sii{
    margin-top:6px;
    color:#64748b;
    font-size:12px;
    line-height:1.35;
    word-break:break-word;
}
.surface-mark-action{
    width:100%;
    margin-top:auto;
    padding:13px 12px;
    border:0;
    border-radius:11px;
    background:#111827;
    color:#fff;
    font-size:14px;
    line-height:1.2;
    font-weight:800;
    cursor:pointer;
    box-sizing:border-box;
    transition:transform .15s ease,background .15s ease;
}
.surface-mark-action:hover{background:#000;transform:translateY(-1px);}
.surface-mark-action:focus-visible{outline:3px solid rgba(30,115,190,.28);outline-offset:2px;}
.surface-mark-card.is-selected{border-color:#111827;box-shadow:0 0 0 2px rgba(17,24,39,.10),0 16px 36px rgba(15,23,42,.11);}
.surface-mark-workspace{
    width:100%;
    max-width:900px;
    margin:8px auto 36px;
    padding:22px;
    background:#fff;
    border:1px solid #e5e7eb;
    border-radius:20px;
    box-shadow:0 12px 34px rgba(15,23,42,.08);
    box-sizing:border-box;
    clear:both;
}
.surface-mark-workspace-title{margin:0 0 16px;color:#0f172a;font-size:20px;line-height:1.3;font-weight:800;}
.surface-mark-workspace #surfaceStampPreview{display:block!important;width:100%;max-width:520px!important;margin:0 auto;}
.surface-mark-workspace #surfaceStampPreview img{width:100%;height:auto;}
.surface-mark-workspace video,.surface-mark-workspace audio{max-width:100%!important;}
.surface-mark-workspace > p{
    width:100%;
    max-width:560px;
    margin:0 auto 14px;
}
.surface-mark-media-label{
    width:100%;
    max-width:560px;
    margin:22px auto 8px;
    color:#0f172a;
    font-size:14px;
    line-height:1.3;
    font-weight:800;
}
.surface-mark-media-block{
    position:relative;
    width:100%;
    max-width:560px;
    margin:0 auto 22px;
    box-sizing:border-box;
}
.surface-mark-video-player{
    display:block;
    width:100%!important;
    height:auto!important;
    max-height:620px;
    border-radius:14px!important;
    background:#000;
    object-fit:contain;
}
.surface-mark-audio-player{
    display:block;
    width:100%!important;
}
.surface-mark-workspace #surfaceStampPreview{
    display:block!important;
    width:100%;
    max-width:560px!important;
    margin:22px auto 0;
}
.surface-mark-workspace #surfaceStampPreview img{
    display:block;
    width:100%!important;
    height:auto!important;
    max-height:720px;
    object-fit:contain!important;
    background:#f8fafc!important;
}

.surface-mark-back-grid-wrap{
    display:flex;
    justify-content:center;
    margin:24px 0 4px;
}
.surface-mark-back-grid{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    min-height:46px;
    padding:12px 18px;
    border:1px solid #cbd5e1;
    border-radius:12px;
    background:#fff;
    color:#0f172a;
    text-decoration:none;
    font-size:14px;
    font-weight:800;
    box-shadow:0 8px 22px rgba(15,23,42,.08);
    transition:transform .18s ease,box-shadow .18s ease,border-color .18s ease;
}
.surface-mark-back-grid:hover{
    transform:translateY(-1px);
    border-color:#94a3b8;
    box-shadow:0 12px 28px rgba(15,23,42,.12);
}
.surface-mark-back-grid:focus-visible{
    outline:3px solid rgba(30,115,190,.25);
    outline-offset:3px;
}
@media(max-width:980px){
    .surface-mark-library{grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;}
}
@media(max-width:700px){
    .surface-mark-library{grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:16px;}
    .surface-mark-card{border-radius:15px;padding:8px;}
    .surface-mark-thumb{border-radius:11px;}
    .surface-mark-card-body{padding:11px 3px 3px;}
    .surface-mark-title{font-size:14px;min-height:38px;}
    .surface-mark-action{font-size:13px;padding:12px 8px;border-radius:10px;}
    .surface-mark-workspace{padding:15px;border-radius:16px;margin-bottom:26px;}
    .surface-mark-workspace-title{font-size:18px;}
}
@media(max-width:390px){
    .surface-mark-library{gap:9px;}
    .surface-mark-card{padding:7px;}
    .surface-mark-type{font-size:9px;letter-spacing:.08em;}
    .surface-mark-title{font-size:13px;}
    .surface-mark-sii{font-size:11px;}
}
</style>';

echo '<div id="surfaceNodeThumbs" class="surface-mark-library">';

foreach($products as $p){
    $post_type = get_post_type($p->ID);

    if ($post_type === 'surface_signal') {
        $thumb = (string) get_post_meta($p->ID, '_surface_signal_image_url', true);
        $signal_target = (string) get_post_meta($p->ID, '_surface_signal_target', true);
        $signal_suffix = (string) get_post_meta($p->ID, '_surface_signal_suffix', true);

        $target_clean = strtolower(trim($signal_target));
        $target_clean = ltrim($target_clean, "/#@\\");
        $target_clean = preg_replace('/[^a-z0-9]/', '', $target_clean);

        $suffix_clean = strtolower(trim($signal_suffix));
        $suffix_clean = preg_replace('/[^a-z0-9]/', '', $suffix_clean);

        $sku = $target_clean . $suffix_clean;
        $surface_type = 'Broadcast SurfaceTooth';
    } else {
        $thumb = (string) get_post_meta($p->ID, '_surface_market_image_url', true);
        $sku = get_post_meta($p->ID, '_sku', true);
        $surface_type = 'Market SurfaceTooth';
    }

    $thumb_src = $thumb;
    if ($thumb_src !== '') {
        $thumb_src .= (strpos($thumb_src, '?') === false ? '?' : '&') . 'v=' . (int) $p->ID;
    }

    echo '<form method="post" action="#surfaceMarkWorkspace" class="surface-mark-card-form">';
    echo '<input type="hidden" name="surface_media_product" value="'.(int)$p->ID.'">';
    echo '<input type="hidden" name="surface_stamp_product" value="1">';
    echo '<div class="surface-mark-card' . ((isset($selected_product_id) && (int)$selected_product_id === (int)$p->ID) ? ' is-selected' : '') . '">';
    echo '<div class="surface-mark-thumb">';
    if ($thumb_src !== '') {
        echo '<img src="' . esc_url($thumb_src) . '" alt="' . esc_attr($p->post_title) . '" referrerpolicy="no-referrer" crossorigin="anonymous">';
    }
    echo '</div>';
    echo '<div class="surface-mark-card-body">';
    echo '<div class="surface-mark-type">' . esc_html($surface_type) . '</div>';
    echo '<div class="surface-mark-title">' . esc_html($p->post_title) . '</div>';
    echo '<div class="surface-mark-sii">@' . esc_html($sku) . '</div>';
    echo '<button type="submit" class="surface-mark-action">SurfaceMark It</button>';
    echo '</div>';
    echo '</div>';
    echo '</form>';
}

echo '</div>';

if ($surface_selected_preview !== '') {
    echo '<section id="surfaceMarkWorkspace" class="surface-mark-workspace" tabindex="-1">';
    echo '<h3 class="surface-mark-workspace-title">Selected Surface Preview</h3>';
    echo $surface_selected_preview;
    echo '<div class="surface-mark-back-grid-wrap">';
    echo '<a href="#surfaceNodeThumbs" class="surface-mark-back-grid" data-surface-back-grid>↑ Back to SurfaceTeeth</a>';
    echo '</div>';
    echo '</section>';
    echo '<script>(function(){var w=document.getElementById("surfaceMarkWorkspace");if(!w)return;window.setTimeout(function(){w.scrollIntoView({behavior:"smooth",block:"start"});try{w.focus({preventScroll:true});}catch(e){}},80);})();</script>';
}

//echo '</select></p>';
//echo '<p><button id="surfaceStampButton" type="submit" name="surface_stamp_product" value="1">Stamp Selected Market Node Image</button></p>';
//echo '</form>';


echo "<script src='https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js'></script>";

echo "<script>

document.addEventListener('DOMContentLoaded', function(){

  var surfaceWorkspace = document.getElementById('surfaceMarkWorkspace');
  if(surfaceWorkspace){
    setTimeout(function(){ surfaceWorkspace.scrollIntoView({behavior:'smooth',block:'start'}); }, 120);
  }

  document.addEventListener('click', function(event){
    var backToGrid = event.target.closest('[data-surface-back-grid]');
    if(!backToGrid) return;

    var grid = document.getElementById('surfaceNodeThumbs');
    if(!grid) return;

    event.preventDefault();
    grid.scrollIntoView({behavior:'smooth',block:'start'});
  });

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


public static function surface_debug_panel($title,$data){
    echo '<div style="background:#111;color:#0f0;padding:12px;margin:12px 0;border-radius:8px;font:12px monospace;"><strong>'.esc_html($title).'</strong><pre style="white-space:pre-wrap;">'.esc_html(print_r($data,true)).'</pre></div>';
}

public static function download_video(){
    if (!is_user_logged_in()) {
        auth_redirect();
    }

    $product_id = isset($_GET['product_id']) ? (int) $_GET['product_id'] : 0;
    if (!$product_id) {
        wp_die('Invalid Surface video.');
    }

    check_admin_referer('surface_download_video_' . $product_id);

    $user_id = get_current_user_id();
    if (!self::user_owns_surface($product_id, $user_id)) {
        wp_die('You cannot download this Surface video.', 403);
    }

    $post_type = get_post_type($product_id);
    $ready_url = ($post_type === 'surface_signal')
        ? (string) get_post_meta($product_id, '_surface_signal_video_ready_url', true)
        : (string) get_post_meta($product_id, '_surface_market_video_ready_url', true);

    if ($ready_url === '') {
        wp_die('The surfaced video is not ready yet.');
    }

    if (!function_exists('download_url')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    @set_time_limit(0);
    $temp_file = download_url($ready_url, 300);
    if (is_wp_error($temp_file) || !is_file($temp_file)) {
        $message = is_wp_error($temp_file) ? $temp_file->get_error_message() : 'Download failed.';
        wp_die(esc_html($message));
    }

    $surface_name = class_exists('SurfacePartnerCore')
        ? SurfacePartnerCore::current_surface_identity()
        : 'surface';
    $sku = self::surface_video_sku($product_id);
    $filename = sanitize_file_name(($surface_name ?: 'surface') . '-' . ($sku ?: 'video') . '.mp4');
    $filesize = filesize($temp_file);

    nocache_headers();
    header('Content-Type: video/mp4');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Transfer-Encoding: binary');
    header('X-Content-Type-Options: nosniff');
    if ($filesize !== false) {
        header('Content-Length: ' . (string) $filesize);
    }

    while (ob_get_level()) {
        ob_end_clean();
    }

    $handle = fopen($temp_file, 'rb');
    if ($handle) {
        while (!feof($handle)) {
            echo fread($handle, 1024 * 1024);
            flush();
        }
        fclose($handle);
    }

    @unlink($temp_file);
    exit;
}

public static function user_owns_surface($product_id, $user_id){
    if (!$product_id || !$user_id) return false;

    return (int) get_post_meta($product_id, '_surface_partner_user_id', true) === (int) $user_id;
}

public static function ffmpeg_path(){
    $candidates = [
        '/usr/bin/ffmpeg',
        '/usr/local/bin/ffmpeg',
        '/opt/homebrew/bin/ffmpeg',
        'ffmpeg',
    ];

    foreach ($candidates as $candidate) {
        if ($candidate === 'ffmpeg') {
            $output = [];
            $code = 1;
            @exec('command -v ffmpeg 2>/dev/null', $output, $code);
            if ($code === 0 && !empty($output[0])) return trim($output[0]);
            continue;
        }

        if (is_executable($candidate)) return $candidate;
    }

    return '';
}

public static function surface_video_sku($product_id){
    $post_type = get_post_type($product_id);

    if ($post_type === 'surface_signal') {
        $target = (string) get_post_meta($product_id, '_surface_signal_target', true);
        $suffix = (string) get_post_meta($product_id, '_surface_signal_suffix', true);

        $target = strtolower(ltrim(trim($target), "/#@\\"));
        $target = preg_replace('/[^a-z0-9]/', '', $target);
        $suffix = preg_replace('/[^a-z0-9]/', '', strtolower(trim($suffix)));

        return $target . $suffix;
    }

    return (string) get_post_meta($product_id, '_sku', true);
}

public static function create_video_mark_png($sku){
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagepng')) {
        return new WP_Error('surface_gd_missing', 'The server image library needed for the video mark is unavailable.');
    }

    $text = '@' . trim((string) $sku);
    $font_size = 24;
    $padding_x = 16;
    $padding_y = 10;
    $font_file = '';

    $font_candidates = [
        ABSPATH . WPINC . '/fonts/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans.ttf',
    ];

    foreach ($font_candidates as $candidate) {
        if (is_readable($candidate)) {
            $font_file = $candidate;
            break;
        }
    }

    if ($font_file !== '' && function_exists('imagettfbbox') && function_exists('imagettftext')) {
        $box = imagettfbbox($font_size, 0, $font_file, $text);
        $text_width = abs($box[2] - $box[0]);
        $text_height = abs($box[7] - $box[1]);
        $width = max(120, $text_width + ($padding_x * 2));
        $height = max(48, $text_height + ($padding_y * 2));
    } else {
        $font_size = 5;
        $text_width = imagefontwidth($font_size) * strlen($text);
        $text_height = imagefontheight($font_size);
        $width = max(120, $text_width + ($padding_x * 2));
        $height = max(42, $text_height + ($padding_y * 2));
    }

    $image = imagecreatetruecolor($width, $height);
    if (!$image) {
        return new WP_Error('surface_mark_create_failed', 'Could not create the temporary SurfaceMark image.');
    }

    imagealphablending($image, false);
    imagesavealpha($image, true);
    $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
    imagefill($image, 0, 0, $transparent);
    imagealphablending($image, true);

    $background = imagecolorallocatealpha($image, 0, 0, 0, 58);
    imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $background);
    $white = imagecolorallocatealpha($image, 255, 255, 255, 0);

    if ($font_file !== '' && function_exists('imagettftext')) {
        $baseline = $height - $padding_y;
        imagettftext($image, $font_size, 0, $padding_x, $baseline, $white, $font_file, $text);
    } else {
        imagestring($image, $font_size, $padding_x, (int) (($height - $text_height) / 2), $text, $white);
    }

    $path = wp_tempnam('surfacemark-video-label-' . sanitize_file_name($sku) . '.png');
    if (!$path) {
        imagedestroy($image);
        return new WP_Error('surface_mark_temp_failed', 'Could not create the temporary SurfaceMark file.');
    }

    if (!imagepng($image, $path)) {
        imagedestroy($image);
        @unlink($path);
        return new WP_Error('surface_mark_write_failed', 'Could not save the temporary SurfaceMark image.');
    }

    imagedestroy($image);
    return $path;
}

public static function stamp_video($product_id, $surface_name){
    $surface_debug=[];
    $surface_debug['ffmpeg_path']=self::ffmpeg_path();
    if (!class_exists('Surface_Backblaze')) {
        return new WP_Error('surface_b2_missing', 'Surface Backblaze is not available.');
    }

    $ffmpeg = self::ffmpeg_path();
    if ($ffmpeg === '') {
        return new WP_Error('surface_ffmpeg_missing', 'FFmpeg is not available to SurfaceMark.');
    }

    $post_type = get_post_type($product_id);
    if ($post_type === 'surface_signal') {
        $video_url = (string) get_post_meta($product_id, '_surface_signal_video_url', true);
        $ready_meta = '_surface_signal_video_ready_url';
        $ready_key_meta = '_surface_signal_video_ready_key';
        $ready_id_meta = '_surface_signal_video_ready_file_id';
    } else {
        $video_url = (string) get_post_meta($product_id, '_surface_market_video_url', true);
        $ready_meta = '_surface_market_video_ready_url';
        $ready_key_meta = '_surface_market_video_ready_key';
        $ready_id_meta = '_surface_market_video_ready_file_id';
    }

    if ($video_url === '') {
        return new WP_Error('surface_video_missing', 'This SurfaceTooth has no video to stamp.');
    }

    $sku = self::surface_video_sku($product_id);
    if ($sku === '') {
        return new WP_Error('surface_sku_missing', 'The Surface identity for this video is missing.');
    }

    if (!function_exists('download_url')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    @set_time_limit(0);

    $input_path = download_url($video_url, 300);
    if (is_wp_error($input_path)) return $input_path;

    $mark_path = self::create_video_mark_png($sku);
    if (is_wp_error($mark_path)) {
        @unlink($input_path);
        return $mark_path;
    }

    $output_path = wp_tempnam('surface-video-' . $product_id . '.mp4');
    if (!$output_path) {
        @unlink($input_path);
        @unlink($mark_path);
        return new WP_Error('surface_temp_failed', 'Could not create the temporary surfaced video.');
    }

    @unlink($output_path);

    $filter = '[1:v]format=rgba[mark];[0:v][mark]overlay=10:H-h-10:format=auto[v]';
    $command = escapeshellarg($ffmpeg)
        . ' -y -i ' . escapeshellarg($input_path)
        . ' -i ' . escapeshellarg($mark_path)
        . ' -filter_complex ' . escapeshellarg($filter)
        . ' -map ' . escapeshellarg('[v]')
        . ' -map 0:a?'
        . ' -c:v libx264 -preset veryfast -crf 23 -pix_fmt yuv420p'
        . ' -c:a aac -b:a 128k -movflags +faststart -shortest '
        . escapeshellarg($output_path)
        . ' 2>&1';

    $output = [];
    $exit_code = 1;
    @exec($command, $output, $exit_code);
    $surface_debug['command']=$command;
    $surface_debug['exit_code']=$exit_code;
    $surface_debug['output']=$output;
    self::surface_debug_panel('SurfaceMark Video Diagnostics',$surface_debug);

    @unlink($input_path);
    @unlink($mark_path);

    if ($exit_code !== 0 || !file_exists($output_path) || filesize($output_path) < 1) {
        @unlink($output_path);
        $detail = !empty($output) ? trim(implode(' | ', array_slice($output, -4))) : 'Unknown FFmpeg error.';
        return new WP_Error('surface_ffmpeg_failed', 'Video stamping failed: ' . $detail);
    }

    $safe_surface = sanitize_file_name($surface_name ?: 'surface');
    $safe_sku = sanitize_file_name($sku);
    $remote_name = 'surface/surfacemark/videos/' . (int) get_current_user_id() . '/' . $safe_surface . '-' . $safe_sku . '-' . time() . '.mp4';

    $upload = Surface_Backblaze::upload_file([
        'file_path' => $output_path,
        'file_name' => $remote_name,
        'mime'      => 'video/mp4',
    ]);

    @unlink($output_path);

    if (is_wp_error($upload)) return $upload;

    update_post_meta($product_id, $ready_meta, esc_url_raw($upload['url']));
    update_post_meta($product_id, $ready_key_meta, sanitize_text_field($upload['file_name']));
    update_post_meta($product_id, $ready_id_meta, sanitize_text_field($upload['file_id']));

    return $upload;
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
