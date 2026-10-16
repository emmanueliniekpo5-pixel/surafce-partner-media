<?php
/**
 * Plugin Name: SurfaceMark
 * Description: Applies the Surface Internet mark to images and videos before download.
 * Version: 1.0.42
 */

if(!defined('ABSPATH')) exit;

class SurfacePartnerMedia {

static function init(){

add_shortcode('surface_media',[__CLASS__,'media']);
add_action('admin_post_surface_download_video',[__CLASS__,'download_video']);
add_action('wp_ajax_surface_start_video_stamp',[__CLASS__,'ajax_start_video_stamp']);
add_action('wp_ajax_surface_video_stamp_status',[__CLASS__,'ajax_video_stamp_status']);
add_action('init',[__CLASS__,'cleanup_stale_video_exports']);

}

private static function service_types(){
    return [
        'artisans','professional_services','home_services','health_wellness',
        'beauty_grooming','education_training','technology_digital','automotive',
        'hospitality_events','logistics_delivery','creative_services','properties',
        'other_services'
    ];
}

private static function is_service_surface($post_id){
    $mode = sanitize_key((string) get_post_meta($post_id, '_surface_signal_mode', true));
    if ($mode === 'service') return true;
    $type = sanitize_key((string) get_post_meta($post_id, '_surface_signal_type', true));
    return in_array($type, self::service_types(), true);
}

private static function is_ride_surface($post_id){
    $mode = sanitize_key((string)get_post_meta($post_id, '_surface_signal_mode', true));
    $type = sanitize_key((string)get_post_meta($post_id, '_surface_signal_type', true));
    return $mode === 'ride' || $type === 'ride';
}

/*
 * Shelf Pins are intentionally separate from Surface PIN.
 * They are partner-owned display preferences for the Shelf/SurfaceCase only.
 */
private static function shelf_pin_meta_key(){
    return '_surface_shelf_pinned_surfaceteeth';
}

private static function get_shelf_pins($user_id){
    $saved = get_user_meta((int)$user_id, self::shelf_pin_meta_key(), true);

    if (!is_array($saved)) {
        $saved = [];
    }

    $pins = [];
    foreach ($saved as $post_id) {
        $post_id = (int)$post_id;
        if ($post_id > 0 && !in_array($post_id, $pins, true)) {
            $pins[] = $post_id;
        }
        if (count($pins) >= 3) break;
    }

    return $pins;
}

private static function shelf_pin_user_owns_surface($post_id, $user_id){
    $post_id = (int)$post_id;
    $user_id = (int)$user_id;
    if ($post_id <= 0 || $user_id <= 0) return false;

    return (int)get_post_meta($post_id, '_surface_partner_user_id', true) === $user_id;
}

private static function save_shelf_pins($user_id, $available_ids){
    if (!isset($_POST['surface_save_shelf_pins'])) return '';

    $nonce = isset($_POST['surface_shelf_pins_nonce'])
        ? sanitize_text_field(wp_unslash($_POST['surface_shelf_pins_nonce']))
        : '';

    if (!wp_verify_nonce($nonce, 'surface_save_shelf_pins')) {
        return 'Shelf pins could not be verified.';
    }

    $available_ids = array_values(array_unique(array_map('intval', (array)$available_ids)));
    $pins = [];

    for ($slot = 1; $slot <= 3; $slot++) {
        $field = 'surface_shelf_pin_' . $slot;
        $post_id = isset($_POST[$field]) ? (int)$_POST[$field] : 0;

        if ($post_id <= 0) continue;
        if (!in_array($post_id, $available_ids, true)) continue;
        if (!self::shelf_pin_user_owns_surface($post_id, $user_id)) continue;
        if (in_array($post_id, $pins, true)) continue;

        $pins[] = $post_id;
    }

    update_user_meta(
        (int)$user_id,
        self::shelf_pin_meta_key(),
        array_slice($pins, 0, 3)
    );

    return 'Pinned SurfaceTeeth updated.';
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
        'relation' => 'AND',
        [
                'key'   => '_surface_partner_user_id',
                'value' => $user
        ],
        [
                'relation' => 'OR',
                [
                        'key'     => '_surface_operations_surfacetooth_status',
                        'compare' => 'NOT EXISTS'
                ],
                [
                        'key'     => '_surface_operations_surfacetooth_status',
                        'value'   => 'suspended',
                        'compare' => '!='
                ]
        ]
]
]);

$surface_shelf_pin_notice = self::save_shelf_pins(
    $user,
    wp_list_pluck($products, 'ID')
);
$surface_shelf_pins = self::get_shelf_pins($user);

ob_start();

$surface_video_notice = '';
$surface_video_error  = '';
$surface_ffmpeg_diagnostics = [];
$surface_video_auto_download_url = '';
$surface_video_diagnostic = self::get_video_diagnostic(get_current_user_id());

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
            if ($video_result->get_error_code() === 'surface_ffmpeg_missing') {
                $surface_ffmpeg_diagnostics = self::ffmpeg_diagnostics();
            }
        } else {
            $surface_video_notice = 'Surfaced Video Ready. Your download will begin automatically.';
            if (is_array($video_result) && !empty($video_result['download_url'])) {
                $surface_video_auto_download_url = esc_url_raw(
                    html_entity_decode(
                        (string) $video_result['download_url'],
                        ENT_QUOTES,
                        'UTF-8'
                    )
                );
            }
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

if ($surface_shelf_pin_notice !== '') {
    echo '<div style="max-width:900px;margin:0 auto 14px;background:#eef6ff;color:#174a7c;padding:10px 12px;border-radius:8px;font-weight:700;box-sizing:border-box;">' . esc_html($surface_shelf_pin_notice) . '</div>';
}

if ($surface_video_auto_download_url !== '') {
    echo '<script>(function(){var u=' . wp_json_encode($surface_video_auto_download_url) . ';window.setTimeout(function(){window.location.href=u;},350);})();</script>';
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
    if ($image_url === '' && self::is_ride_surface($product_id)) {
        $image_url = (string)get_post_meta($product_id, '_surface_ride_vehicle_photo_url', true);
    }
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
if($image_url || $video_url || $audio_url){

// SHOW VIDEO/AUDIO PREVIEW IF EXISTS
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
// Use the same existing identity lookup as the video export, not the workspace's unrelated $sku variable.
$video_mark_sku = self::surface_video_sku($product_id);
echo '<div class="surface-mark-media-label surface-mark-video-label">Surface Video</div>';
echo '<div class="surface-mark-media-block surface-mark-video-block">';

// The preview always uses the permanent original video from Backblaze.
// The overlays are a layout preview only; FFmpeg creates the actual downloadable export.
echo '<div class="surface-video-editor" data-surface-video-editor>';
echo '<div class="surface-video-preview" data-surface-video-preview>';
echo '<video controls class="surface-mark-video-player"><source src="' . esc_url($video_url) . '" type="video/mp4"></video>';
echo '<div class="surface-video-overlay" data-overlay="mark" style="left:4%;top:78%">@' . esc_html($video_mark_sku) . '</div>';
echo '</div>';
echo '<p class="surface-video-help">Drag the SurfaceMark directly on the video to position it.</p>';
echo '<form method="post" class="surface-mark-video-form">';
echo '<input type="hidden" name="surface_video_product" value="' . (int) $product_id . '">';
echo '<input type="hidden" name="surface_video_nonce" value="' . esc_attr(wp_create_nonce('surface_stamp_video_' . $product_id)) . '">';
echo '<input type="hidden" name="surface_mark_x" value="4"><input type="hidden" name="surface_mark_y" value="78">';
echo '<button type="button" class="surface-video-export" name="surface_stamp_video" value="1">Surface and download video</button>';
echo '</form></div>';

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
if ($image_url !== '') {
    echo "<img src='" . esc_url($image_url) . "' style='max-width:100%;height:auto;display:block;object-fit:contain;background:#000;border-radius:10px;'>";
    echo '<a href="#" id="downloadStampedImage" data-filename="' . esc_attr($download_name) . '" onclick="event.preventDefault();var btn=this;var target=document.getElementById(\'surfaceStampPreview\');if(!target){alert(\'Preview not found\');return false;}var oldDisplay=btn.style.display;btn.style.display=\'none\';html2canvas(target,{scale:2,useCORS:true,allowTaint:true,backgroundColor:null}).then(function(canvas){btn.style.display=oldDisplay;var dataUrl=canvas.toDataURL(\'image/png\');var fileName=\'' . esc_attr($download_name) . '\';if(window.SurfaceAndroidDownloads&&typeof window.SurfaceAndroidDownloads.saveDataUrl===\'function\'){window.SurfaceAndroidDownloads.saveDataUrl(fileName,dataUrl);return;}var link=document.createElement(\'a\');link.download=fileName;link.href=dataUrl;document.body.appendChild(link);link.click();document.body.removeChild(link);}).catch(function(){btn.style.display=oldDisplay;alert(\'Download failed\');});return false;" style="position:absolute;top:12px;right:12px;background:rgba(0,0,0,0.45);color:#fff;width:38px;height:38px;display:flex;align-items:center;justify-content:center;border-radius:999px;text-decoration:none;font-size:18px;">⬇</a>';
    echo '<div style="position:absolute;bottom:0;left:0;display:flex;flex-direction:column;gap:2px;align-items:flex-start;max-width:78%;">
      <span style="margin:0 0 0 0;background:rgba(0,0,0,0.55);color:#fff;padding:6px 10px;border-radius:6px;font-weight:700;font-size:13px;line-height:1.05;letter-spacing:0.2px;display:inline-block;">@' . esc_html($sku) . '</span>
    </div>';
}
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
        $node_type = self::is_ride_surface($node_id) ? 'RIDE SURFACETOOTH' : (self::is_service_surface($node_id) ? 'SERVICE SURFACETOOTH' : 'BROADCAST SURFACETOOTH');
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
.surface-video-editor{max-width:560px;margin:12px auto 22px;padding:14px;border:1px solid #dbe2ea;border-radius:14px;background:#f8fafc;box-sizing:border-box}
.surface-video-preview{position:relative;width:100%;line-height:0;overflow:hidden;border-radius:10px;background:#000;touch-action:pan-y}
.surface-video-preview video{display:block;width:100%;height:auto;max-height:620px;object-fit:contain}
.surface-video-overlay{position:absolute;left:10%;top:70%;z-index:4;max-width:80%;padding:6px 9px;background:rgba(0,0,0,.72);color:#fff;border:1px dashed rgba(255,255,255,.85);border-radius:5px;font:700 12px/1.25 sans-serif;line-height:1.25;cursor:move;pointer-events:auto;touch-action:none;user-select:none;-webkit-user-select:none;white-space:pre-wrap;overflow-wrap:anywhere}
.surface-video-overlay.is-dragging{outline:2px solid #22c55e}
.surface-video-editor label{display:block;margin:10px 0 5px;font-size:13px;font-weight:700;color:#0f172a}
.surface-video-editor input[type=text]{width:100%;min-height:40px;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;box-sizing:border-box}
.surface-video-editor .surface-video-help{font-size:12px;color:#475569;line-height:1.45;margin:8px 0}
.surface-video-editor .surface-video-export{display:inline-flex;align-items:center;justify-content:center;margin-top:12px;padding:11px 16px;border:0;border-radius:9px;background:#111827;color:#fff;font-weight:800;cursor:pointer}
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

echo '<section class="surface-shelf-pin-panel" style="max-width:900px;margin:0 auto 22px;padding:16px;background:#fff;border:1px solid #e5e7eb;border-radius:14px;box-sizing:border-box;">';
echo '<div style="font-size:16px;font-weight:800;color:#111827;margin-bottom:5px;">Pinned SurfaceTeeth</div>';
echo '<div style="font-size:13px;color:#6b7280;line-height:1.5;margin-bottom:14px;">Choose up to 3 SurfaceTeeth to stay fixed at the top of your Shelf. Pin 1 appears first, then Pin 2 and Pin 3. This is separate from Surface PIN.</div>';
echo '<form method="post" action="#surfaceNodeThumbs">';
echo wp_nonce_field('surface_save_shelf_pins', 'surface_shelf_pins_nonce', true, false);
echo '<div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;">';

for ($slot = 1; $slot <= 3; $slot++) {
    $selected_pin = isset($surface_shelf_pins[$slot - 1]) ? (int)$surface_shelf_pins[$slot - 1] : 0;

    echo '<label style="display:block;font-size:12px;font-weight:800;color:#374151;">Pin ' . (int)$slot;
    echo '<select name="surface_shelf_pin_' . (int)$slot . '" style="display:block;width:100%;margin-top:6px;padding:10px;border:1px solid #d1d5db;border-radius:9px;background:#fff;color:#111827;">';
    echo '<option value="0">Not pinned</option>';

    foreach ($products as $pin_product) {
        $pin_id = (int)$pin_product->ID;
        $pin_type = get_post_type($pin_id);
        $pin_token = '';

        if ($pin_type === 'surface_signal') {
            $pin_token = (string)get_post_meta($pin_id, '_surface_kxcode', true);
            if ($pin_token === '') {
                $pin_token =
                    (string)get_post_meta($pin_id, '_surface_signal_target', true)
                    . (string)get_post_meta($pin_id, '_surface_signal_suffix', true);
            }
        } else {
            $pin_token = (string)get_post_meta($pin_id, '_sku', true);
        }

        $pin_label = get_the_title($pin_id);
        if ($pin_token !== '') {
            $pin_label .= ' /' . ltrim($pin_token, '/#@');
        }

        echo '<option value="' . $pin_id . '"' . selected($selected_pin, $pin_id, false) . '>' . esc_html($pin_label) . '</option>';
    }

    echo '</select>';
    echo '</label>';
}

echo '</div>';
echo '<button type="submit" name="surface_save_shelf_pins" value="1" style="margin-top:12px;border:0;border-radius:10px;background:#111827;color:#fff;padding:11px 15px;font-weight:800;cursor:pointer;">Save Shelf Pins</button>';
echo '</form>';
echo '</section>';
echo '<style>@media(max-width:700px){.surface-shelf-pin-panel form>div{grid-template-columns:1fr!important;}}</style>';

echo '<div id="surfaceNodeThumbs" class="surface-mark-library">';

foreach($products as $p){
    $post_type = get_post_type($p->ID);

    if ($post_type === 'surface_signal') {
        $thumb = (string) get_post_meta($p->ID, '_surface_signal_image_url', true);
        if ($thumb === '' && self::is_ride_surface($p->ID)) {
            $thumb = (string)get_post_meta($p->ID, '_surface_ride_vehicle_photo_url', true);
        }
        $signal_target = (string) get_post_meta($p->ID, '_surface_signal_target', true);
        $signal_suffix = (string) get_post_meta($p->ID, '_surface_signal_suffix', true);

        $target_clean = strtolower(trim($signal_target));
        $target_clean = ltrim($target_clean, "/#@\\");
        $target_clean = preg_replace('/[^a-z0-9]/', '', $target_clean);

        $suffix_clean = strtolower(trim($signal_suffix));
        $suffix_clean = preg_replace('/[^a-z0-9]/', '', $suffix_clean);

        $sku = $target_clean . $suffix_clean;
        if (self::is_ride_surface($p->ID)) {
            $surface_type = 'Ride SurfaceTooth';
        } elseif (self::is_service_surface($p->ID)) {
            $surface_type = 'Service SurfaceTooth';
        } else {
            $surface_type = 'Broadcast SurfaceTooth';
        }
    } else {
        $thumb = (string) get_post_meta($p->ID, '_surface_market_image_url', true);
        $sku = get_post_meta($p->ID, '_sku', true);
        $surface_type = 'Market SurfaceTooth';
    }

    $thumb_src = $thumb;
    if ($thumb_src !== '') {
        $thumb_src .= (strpos($thumb_src, '?') === false ? '?' : '&') . 'v=' . (int) $p->ID;
    }

    // SurfaceMark It only selects the SurfaceTooth and opens its preview.
    // Video stamping is deliberately started only from the video preview controls.
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

  document.querySelectorAll('.surface-mark-video-form').forEach(function(form){
    var button = form.querySelector('button[name=surface_stamp_video]');
    if(!button) return;
    button.addEventListener('click', function(event){
      var product = form.querySelector('input[name=surface_video_product]');
      var nonce = form.querySelector('input[name=surface_video_nonce]');
      if(!product || !nonce) { window.alert('Unable to start video stamping. Please refresh the page and try again.'); return; }

      event.preventDefault();
      if(button.dataset.surfaceBusy === '1') return false;
      button.dataset.surfaceBusy = '1';
      button.disabled = true;
      button.style.opacity = '0.7';
      button.style.cursor = 'wait';
      button.textContent = '…';
      button.setAttribute('title','Creating Video SurfaceMark');

      var status = document.getElementById('surfaceVideoStampStatus');
      if(!status){
        status=document.createElement('div');
        status.id='surfaceVideoStampStatus';
        status.style.cssText='position:fixed;left:50%;bottom:24px;transform:translateX(-50%);z-index:99999;background:#111827;color:#fff;padding:12px 16px;border-radius:10px;font:600 13px/1.4 sans-serif;box-shadow:0 8px 30px rgba(0,0,0,.25);max-width:90vw;';
        document.body.appendChild(status);
      }
      status.textContent='Starting video SurfaceMark…';

      var data=new URLSearchParams();
      data.append('action','surface_start_video_stamp');
      data.append('product_id',product.value);
      data.append('nonce',nonce.value);
      ['mark_x','mark_y'].forEach(function(key){var field=form.querySelector('[name=surface_'+key+']');if(field)data.append(key,field.value);});
      fetch('" . esc_js(admin_url('admin-ajax.php')) . "',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:data.toString()})
      .then(function(r){return r.text().then(function(t){var parsed;try{parsed=JSON.parse(t);}catch(e){throw new Error('The video service returned an unexpected response. Please try again.');}if(!r.ok)throw new Error((parsed&&parsed.data&&parsed.data.message)||('HTTP '+r.status+' while starting the video export.'));return parsed;});})
      .then(function(json){
        if(!json || !json.success || !json.data || !json.data.job_id) throw new Error((json&&json.data&&json.data.message)||'Video stamping could not be started.');
        var jobId=json.data.job_id;
        var poll=setInterval(function(){
          var p=new URLSearchParams(); p.append('action','surface_video_stamp_status'); p.append('job_id',jobId);
          fetch('" . esc_js(admin_url('admin-ajax.php')) . "',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:p.toString()})
          .then(function(r){return r.text().then(function(t){var parsed;try{parsed=JSON.parse(t);}catch(e){throw new Error('The video service returned an unexpected status. Please try again.');}if(!r.ok)throw new Error((parsed&&parsed.data&&parsed.data.message)||('HTTP '+r.status+' while checking video status.'));return parsed;});})
          .then(function(j){
            if(!j||!j.success) throw new Error((j&&j.data&&j.data.message)||'Video status could not be read.');
            var d=j.data||{};
            var publicStage={
              'Starting':'Preparing your video…',
              'Backblaze download started':'Preparing your video…',
              'Backblaze download complete':'Preparing your video…',
              'Creating SurfaceMark':'Applying SurfaceMark…',
              'FFmpeg started':'Applying SurfaceMark…',
              'Stamped file ready':'Finishing your video…',
              'Download URL ready':'Your video is ready. Starting download…'
            };
            var mainText=publicStage[d.stage]||'Preparing your video…';
            if(status.firstChild && status.firstChild.nodeType===3){status.firstChild.textContent=mainText;}else{status.insertBefore(document.createTextNode(mainText),status.firstChild);}
            if(d.status==='complete' && d.download_url){
              clearInterval(poll); status.textContent='SurfaceMark ready — starting download…';
              var downloadLink=document.createElement('a');
              downloadLink.href=d.download_url;
              downloadLink.textContent='Download surfaced video';
              downloadLink.style.cssText='display:inline-block;margin-left:10px;color:#fff;text-decoration:underline;font-weight:700';
              status.appendChild(downloadLink);
              // Use the fresh, tokenized URL returned by this completed job, not any legacy saved-video URL.
              window.location.assign(d.download_url);
              setTimeout(function(){if(status&&status.parentNode)status.parentNode.removeChild(status);},10000);
            } else if(d.status==='failed'){
              clearInterval(poll);
              if(status.firstChild&&status.firstChild.nodeType===3){status.firstChild.textContent='SurfaceMark could not be completed. Please try again.';}
              button.disabled=false; button.dataset.surfaceBusy=''; button.style.opacity='1'; button.style.cursor='pointer'; button.textContent='⬇';
            }
          })
          .catch(function(){clearInterval(poll); if(status.firstChild&&status.firstChild.nodeType===3){status.firstChild.textContent='Unable to check video progress. Please try again.';} button.disabled=false; button.dataset.surfaceBusy=''; button.style.opacity='1'; button.style.cursor='pointer'; button.textContent='⬇';});
        },1500);
      })
      .catch(function(){
        if(status.firstChild&&status.firstChild.nodeType===3){status.firstChild.textContent='Unable to start video stamping. Please try again.';}else{status.insertBefore(document.createTextNode('Unable to start video stamping. Please try again.'),status.firstChild);}
        button.disabled=false; button.dataset.surfaceBusy=''; button.style.opacity='1'; button.style.cursor='pointer'; button.textContent='⬇';
      });
      return false;
    });
  });

  document.querySelectorAll('[data-surface-video-editor]').forEach(function(editor){
    var preview=editor.querySelector('[data-surface-video-preview]');
    var form=editor.querySelector('.surface-mark-video-form');
    var mark=editor.querySelector('[data-overlay=mark]');
    if(!preview||!form||!mark) return;
    var xField=form.querySelector('[name=surface_mark_x]');
    var yField=form.querySelector('[name=surface_mark_y]');
    if(!xField||!yField) return;
    var dragging=false,offsetX=0,offsetY=0;
    function updatePosition(e){
      if(!dragging)return;
      var r=preview.getBoundingClientRect();
      var maxLeft=Math.max(0,r.width-mark.offsetWidth);
      var maxTop=Math.max(0,r.height-mark.offsetHeight);
      var left=Math.max(0,Math.min(maxLeft,e.clientX-r.left-offsetX));
      var top=Math.max(0,Math.min(maxTop,e.clientY-r.top-offsetY));
      var x=(left/r.width)*100;
      var y=(top/r.height)*100;
      mark.style.left=x+'%';mark.style.top=y+'%';
      xField.value=x.toFixed(2);yField.value=y.toFixed(2);
      e.preventDefault();
    }
    mark.addEventListener('pointerdown',function(e){
      if(e.button!==undefined && e.button!==0)return;
      e.stopPropagation();
      var mr=mark.getBoundingClientRect();
      dragging=true;mark.classList.add('is-dragging');mark.style.touchAction='none';
      offsetX=e.clientX-mr.left;offsetY=e.clientY-mr.top;
      try{mark.setPointerCapture(e.pointerId);}catch(err){}
      e.preventDefault();
    });
    document.addEventListener('pointermove',updatePosition,{passive:false});
    function stop(){dragging=false;mark.classList.remove('is-dragging');}
    mark.addEventListener('pointerup',stop);mark.addEventListener('pointercancel',stop);
    document.addEventListener('pointerup',stop);document.addEventListener('pointercancel',stop);
  });

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

      var dataUrl = resizedCanvas.toDataURL('image/png');

      if (
        window.SurfaceAndroidDownloads &&
        typeof window.SurfaceAndroidDownloads.saveDataUrl === 'function'
      ) {
        window.SurfaceAndroidDownloads.saveDataUrl(filename, dataUrl);
        return;
      }

      var link = document.createElement('a');
      link.download = filename;
      link.href = dataUrl;

      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);

    }).catch(function(err){
      console.log(err);
      alert('Download failed');
    });
  }

  document.addEventListener('click', function(e) {
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
'#' + surface + '\\n' +
'#' + sku + '\\n\\n' +
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
'#' + surface + '\\n' +
'#' + sku + '\\n\\n' +
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

private static function video_position($value){
    if (!is_numeric($value)) return 0.0;
    return max(0.0, min(100.0, (float)$value));
}

public static function ajax_start_video_stamp(){
    if (!is_user_logged_in()) wp_send_json_error(['message'=>'Please sign in again.'],401);
    $product_id = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    $user_id = get_current_user_id();
    if (!$product_id || !wp_verify_nonce($nonce,'surface_stamp_video_'.$product_id)) {
        wp_send_json_error(['message'=>'Video request could not be verified.'],403);
    }
    if (!self::user_owns_surface($product_id,$user_id)) {
        wp_send_json_error(['message'=>'You cannot process this Surface video.'],403);
    }
    $post_type=get_post_type($product_id);
    $video_url=(string)get_post_meta($product_id,$post_type==='surface_signal'?'_surface_signal_video_url':'_surface_market_video_url',true);
    if ($video_url==='') wp_send_json_error(['message'=>'This SurfaceTooth has no video to stamp.'],400);
    if (self::ffmpeg_path()==='') wp_send_json_error(['message'=>'FFmpeg is not available to the website PHP process.'],500);
    $settings = [
        'mark_x' => self::video_position($_POST['mark_x'] ?? 4),
        'mark_y' => self::video_position($_POST['mark_y'] ?? 78),
    ];
    $job=self::start_video_stamp_job($product_id,SurfacePartnerCore::current_surface_identity(),$user_id,$settings);
    if (is_wp_error($job)) wp_send_json_error(['message'=>$job->get_error_message()],500);
    wp_send_json_success($job);
}

public static function ajax_video_stamp_status(){
    if (!is_user_logged_in()) wp_send_json_error(['message'=>'Please sign in again.'],401);
    $job_id=isset($_POST['job_id'])?sanitize_key(wp_unslash($_POST['job_id'])):'';
    if ($job_id==='') wp_send_json_error(['message'=>'Missing video job.'],400);
    $job=get_transient('surface_video_job_'.$job_id);
    if (!is_array($job) || (int)($job['user_id']??0)!==get_current_user_id()) {
        wp_send_json_error(['message'=>'Video job is unavailable.'],404);
    }
    $started=(float)($job['started_at']??0); $updated=(float)($job['updated_at']??0);
    $download_url = (string)($job['download_url'] ?? '');
    // The background CLI worker cannot mint a nonce tied to the visitor's browser session.
    // Mint/attach the download nonce here, inside the authenticated AJAX request instead.
    if (($job['status'] ?? '') === 'complete' && $download_url !== '') {
        $download_url = remove_query_arg('_wpnonce', $download_url);
        $download_url = add_query_arg(
            '_wpnonce',
            wp_create_nonce('surface_download_video_' . (int)($job['product_id'] ?? 0)),
            $download_url
        );
    }
    wp_send_json_success([
        'status'=>(string)($job['status']??'queued'),
        'stage'=>(string)($job['stage']??'Queued'),
        'elapsed'=>$started>0?number_format(max(0,microtime(true)-$started),1):'',
        'download_url'=>$download_url,
    ]);
}

public static function video_job_update($job_id,$status,$stage,$detail='',$extra=[]){
    $job_id=sanitize_key((string)$job_id); if($job_id==='') return;
    $key='surface_video_job_'.$job_id; $job=get_transient($key); if(!is_array($job)) return;
    $now=microtime(true); if(empty($job['started_at'])) $job['started_at']=$now;
    $job['status']=sanitize_key($status); $job['stage']=sanitize_text_field($stage); $job['detail']=sanitize_text_field($detail); $job['updated_at']=$now;
    foreach((array)$extra as $k=>$v) $job[$k]=$v;
    set_transient($key,$job,2*HOUR_IN_SECONDS);
}

private static function php_cli_path(){
    $candidates=[];
    if(defined('PHP_BINDIR')) $candidates[]=trailingslashit(PHP_BINDIR).'php';
    $candidates[]='/usr/bin/php'; $candidates[]='/usr/local/bin/php';
    foreach(array_unique($candidates) as $candidate) if(is_executable($candidate)) return $candidate;
    if(function_exists('exec')){ $out=[];$code=1; @exec('command -v php 2>/dev/null',$out,$code); if($code===0&&!empty($out[0])&&is_executable(trim($out[0]))) return trim($out[0]); }
    return '';
}

private static function start_video_stamp_job($product_id,$surface_name,$user_id,$settings=[]){
    $php=self::php_cli_path();
    if($php==='') return new WP_Error('surface_php_cli_missing','The VPS PHP command-line worker is not available.');
    $job_id=strtolower(wp_generate_password(32,false,false));
    $key='surface_video_job_'.$job_id; $now=microtime(true);
    set_transient($key,['job_id'=>$job_id,'product_id'=>(int)$product_id,'user_id'=>(int)$user_id,'status'=>'queued','stage'=>'Queued','detail'=>'Video stamping worker is being started.','started_at'=>$now,'updated_at'=>$now,'settings'=>(array)$settings],2*HOUR_IN_SECONDS);
    $worker_path=trailingslashit(get_temp_dir()).'surface-video-worker-'.$job_id.'.php';
    $worker_log_path=trailingslashit(get_temp_dir()).'surface-video-worker-'.$job_id.'.log';
    $wp_load=ABSPATH.'wp-load.php';
    $worker="<?php\n";
    $worker.="\$surface_log_path=".var_export($worker_log_path,true).";\n";
    $worker.="function surface_worker_log(\$message){ global \$surface_log_path; @file_put_contents(\$surface_log_path,'['.date('c').'] '.(string)\$message.PHP_EOL,FILE_APPEND|LOCK_EX); }\n";
    $worker.="surface_worker_log('Worker process started; PHP '.PHP_VERSION.'.');\n";
    $worker.="set_error_handler(function(\$severity,\$message,\$file,\$line){ surface_worker_log('PHP ERROR ['.\$severity.'] '.\$message.' in '.\$file.':'.\$line); return false; });\n";
    $worker.="register_shutdown_function(function(){ \$e=error_get_last(); if(\$e && in_array(\$e['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR],true)){ surface_worker_log('FATAL PHP ERROR: '.\$e['message'].' in '.\$e['file'].':'.\$e['line']); if(function_exists('get_transient') && class_exists('SurfacePartnerMedia')) SurfacePartnerMedia::video_job_update(".var_export($job_id,true).",'failed','PHP worker fatal error',(string)\$e['message'],['error'=>(string)\$e['message']]); } });\n";
    $worker.="surface_worker_log('Loading WordPress bootstrap: '.".var_export($wp_load,true).");\n";
    $worker.="require_once ".var_export($wp_load,true).";\n";
    $worker.="surface_worker_log('WordPress bootstrap loaded.');\n";
    $worker.="\$user_id=(int)(\$argv[1]??0); \$product_id=(int)(\$argv[2]??0); \$surface_name=(string)(\$argv[3]??'surface'); \$job_id=(string)(\$argv[4]??''); wp_set_current_user(\$user_id);\n";
    // All generated workers share one lock, so only one FFmpeg stamp can run at a time.
    $queue_lock_path = trailingslashit(get_temp_dir()) . 'surface-video-stamp.lock';
    $worker .= "surface_worker_log('Queue lock investigation started. Lock path: ' ." . var_export($queue_lock_path, true) . "); \$queue_lock=@fopen(" . var_export($queue_lock_path, true) . ",'c'); if(!\$queue_lock){ surface_worker_log('FAILED: could not open/create queue lock file; check temp-directory permissions.'); if(class_exists('SurfacePartnerMedia')) SurfacePartnerMedia::video_job_update(\$job_id,'failed','Queue lock file error','Could not open/create queue lock file; check temp-directory permissions.',['error'=>'Could not open/create queue lock file.']); exit(1); } \$surface_lock_wait_started=microtime(true); \$surface_next_lock_report=0; while(!@flock(\$queue_lock,LOCK_EX|LOCK_NB)){ \$surface_waited=(int)(microtime(true)-\$surface_lock_wait_started); if(\$surface_waited>=\$surface_next_lock_report){ surface_worker_log('Queue lock is BUSY; this worker is waiting. Wait seconds: '.\$surface_waited.'; worker PID: '.getmypid().'.'); if(class_exists('SurfacePartnerMedia')) SurfacePartnerMedia::video_job_update(\$job_id,'queued','Waiting for queue lock','Queue lock is busy; worker PID '.getmypid().'; waited '.\$surface_waited.' seconds.'); \$surface_next_lock_report=\$surface_waited+5; } usleep(250000); } surface_worker_log('Queue lock acquired after '.number_format(microtime(true)-\$surface_lock_wait_started,2).' seconds; worker PID: '.getmypid().'.');\n";
    $worker .= "\$job_state=get_transient('surface_video_job_'.sanitize_key(\$job_id)); if(!is_array(\$job_state) || (\$job_state['status']??'')!=='queued'){ flock(\$queue_lock,LOCK_UN); fclose(\$queue_lock); @unlink(__FILE__); exit(0); }\n";
    $worker .= "if(!class_exists('SurfacePartnerMedia')){ surface_worker_log('FAILED: SurfacePartnerMedia class is unavailable after WordPress bootstrap.'); flock(\$queue_lock,LOCK_UN); fclose(\$queue_lock); exit(1); } surface_worker_log('SurfacePartnerMedia class found; entering stamp_video().'); SurfacePartnerMedia::video_job_update(\$job_id,'running','Starting','Video stamping worker acquired the queue lock.'); \$result=SurfacePartnerMedia::stamp_video(\$product_id,\$surface_name,\$job_id); if(is_wp_error(\$result)){ surface_worker_log('STAMP FAILED: '.\$result->get_error_message()); SurfacePartnerMedia::video_job_update(\$job_id,'failed','Video stamping failed',\$result->get_error_message(),['error'=>\$result->get_error_message()]); } else { surface_worker_log('stamp_video() returned without WP_Error.'); } flock(\$queue_lock,LOCK_UN); fclose(\$queue_lock); surface_worker_log('Worker finished and released queue lock.'); @unlink(__FILE__);\n";
    if(@file_put_contents($worker_path,$worker,LOCK_EX)===false){delete_transient($key);return new WP_Error('surface_worker_create_failed','The VPS could not create the video stamping worker.');}
    $command='nohup '.escapeshellarg($php).' '.escapeshellarg($worker_path).' '.escapeshellarg((string)$user_id).' '.escapeshellarg((string)$product_id).' '.escapeshellarg((string)$surface_name).' '.escapeshellarg($job_id).' >/dev/null 2>&1 & echo $!';
    $out=[];$code=1; @exec($command,$out,$code);
    if($code!==0){@file_put_contents($worker_log_path,'['.date('c').'] FAILED: background launch command returned exit code '.(int)$code.'.'.PHP_EOL,FILE_APPEND|LOCK_EX);@unlink($worker_path);delete_transient($key);return new WP_Error('surface_worker_start_failed','The VPS could not start the background video worker (launch exit code '.(int)$code.').');}
    $launch_pid=isset($out[0])?trim((string)$out[0]):'';
    $job_state=get_transient($key);
    if(is_array($job_state)){ $job_state['launch_pid']=$launch_pid; $job_state['updated_at']=microtime(true); set_transient($key,$job_state,2*HOUR_IN_SECONDS); }
    @file_put_contents($worker_log_path,'['.date('c').'] Launch command returned exit code 0; reported PID: '.($launch_pid!==''?$launch_pid:'(none)').PHP_EOL,FILE_APPEND|LOCK_EX);
    // Keep the job queued until its worker acquires the shared lock.
    return ['job_id'=>$job_id,'status'=>'queued'];
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

    $token = isset($_GET['surface_video_token'])
        ? sanitize_text_field(wp_unslash($_GET['surface_video_token']))
        : '';

    if ($token !== '') {
        $token_hash = hash('sha256', $token);
        $transient_key = 'surface_video_export_' . $token_hash;
        $export = get_transient($transient_key);

        // Transients may be unavailable across requests when a host's object
        // cache is inconsistent. The private temp-directory sidecar is a
        // durable fallback for this short-lived download handoff.
        $sidecar_path = self::video_export_sidecar_path($token_hash);
        if (!is_array($export) && is_readable($sidecar_path)) {
            $sidecar_data = json_decode((string) @file_get_contents($sidecar_path), true);
            if (is_array($sidecar_data)) {
                $export = $sidecar_data;
                set_transient($transient_key, $export, 30 * MINUTE_IN_SECONDS);
            }
        }

        if (!is_array($export)
            || (int) ($export['user_id'] ?? 0) !== $user_id
            || (int) ($export['product_id'] ?? 0) !== $product_id
            || empty($export['path'])
            || !is_file($export['path'])
            || !is_readable($export['path'])) {
            // Do not erase a potentially valid export record on a transient
            // read failure; allow the sidecar fallback to be used on retry.
            wp_die('This surfaced video download has expired or is unavailable. Please stamp the video again.');
        }

        $temp_file = (string) $export['path'];
        $filename = sanitize_file_name((string) ($export['filename'] ?? 'surfaced-video.mp4'));

        // Keep the export token alive until the file has actually streamed.
        // Longer downloads can be retried by the browser/proxy; deleting the
        // transient before streaming turns a retry into a false "expired" page.
        self::stream_video_download($temp_file, $filename, true, $transient_key);
    }

    wp_die('The surfaced video download is unavailable. Please stamp the video again.');
}

public static function stream_video_download($temp_file, $filename, $delete_after = true, $transient_key = ''){
    if (!is_file($temp_file) || !is_readable($temp_file)) {
        wp_die('The surfaced video file is unavailable.');
    }

    @set_time_limit(0);
    @ignore_user_abort(true);

    $filesize = filesize($temp_file);
    nocache_headers();
    header('Content-Type: video/mp4');
    header('Content-Disposition: attachment; filename="' . sanitize_file_name($filename) . '"');
    header('Content-Transfer-Encoding: binary');
    header('X-Content-Type-Options: nosniff');
    if ($filesize !== false) {
        header('Content-Length: ' . (string) $filesize);
    }

    while (ob_get_level()) {
        ob_end_clean();
    }

    $handle = fopen($temp_file, 'rb');
    if (!$handle) {
        if ($delete_after) @unlink($temp_file);
        wp_die('The surfaced video could not be opened.');
    }

    $stream_complete = true;
    while (!feof($handle)) {
        $chunk = fread($handle, 1024 * 1024);
        if ($chunk === false) {
            $stream_complete = false;
            break;
        }
        echo $chunk;
        flush();
        if (function_exists('connection_aborted') && connection_aborted()) {
            $stream_complete = false;
            break;
        }
    }
    fclose($handle);

    // Only consume the one-time download token after the entire temporary
    // file has been streamed successfully. If the connection is interrupted,
    // leave the token/file available so the browser can retry within its TTL.
    if ($stream_complete) {
        if ($transient_key !== '') {
            delete_transient($transient_key);
            $prefix = 'surface_video_export_';
            if (strpos($transient_key, $prefix) === 0) {
                $token_hash = substr($transient_key, strlen($prefix));
                if (preg_match('/^[a-f0-9]{64}$/', $token_hash)) {
                    @unlink(self::video_export_sidecar_path($token_hash));
                }
            }
        }
        if ($delete_after) @unlink($temp_file);
    }
    exit;
}


public static function user_owns_surface($product_id, $user_id){
    if (!$product_id || !$user_id) return false;

    return (int) get_post_meta($product_id, '_surface_partner_user_id', true) === (int) $user_id;
}

public static function php_function_available($function_name){
    if (!function_exists($function_name)) return false;

    $disabled = array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));
    return !in_array($function_name, $disabled, true);
}

public static function ffmpeg_diagnostics(){
    $ffmpeg = '/usr/bin/ffmpeg';
    $ffprobe = '/usr/bin/ffprobe';
    $exec_available = self::php_function_available('exec');
    $shell_exec_available = self::php_function_available('shell_exec');
    $proc_open_available = self::php_function_available('proc_open');
    $open_basedir = trim((string) ini_get('open_basedir'));
    $exec_output = [];
    $exec_code = null;
    $exec_message = '';

    if ($exec_available) {
        @exec(escapeshellarg($ffmpeg) . ' -version 2>&1', $exec_output, $exec_code);
        $exec_message = !empty($exec_output) ? trim((string) $exec_output[0]) : '';
    }

    $temp_dir = get_temp_dir();

    return [
        'php_sapi' => PHP_SAPI,
        'php_version' => PHP_VERSION,
        'exec_available' => $exec_available,
        'shell_exec_available' => $shell_exec_available,
        'proc_open_available' => $proc_open_available,
        'ffmpeg_exists' => file_exists($ffmpeg),
        'ffmpeg_executable' => is_executable($ffmpeg),
        'ffprobe_exists' => file_exists($ffprobe),
        'ffprobe_executable' => is_executable($ffprobe),
        'open_basedir' => $open_basedir !== '' ? $open_basedir : 'Not configured',
        'temp_directory' => $temp_dir,
        'temp_writable' => is_dir($temp_dir) && is_writable($temp_dir),
        'exec_exit_code' => $exec_code,
        'exec_first_line' => $exec_message,
    ];
}

public static function render_ffmpeg_diagnostics($diagnostics){
    $yes = '<span style="color:#047857;font-weight:800;">Available</span>';
    $no = '<span style="color:#b91c1c;font-weight:800;">Unavailable</span>';
    $rows = [
        'Website PHP mode' => (string) ($diagnostics['php_sapi'] ?? 'Unknown'),
        'PHP version' => (string) ($diagnostics['php_version'] ?? 'Unknown'),
        'exec()' => !empty($diagnostics['exec_available']) ? $yes : $no,
        'shell_exec()' => !empty($diagnostics['shell_exec_available']) ? $yes : $no,
        'proc_open()' => !empty($diagnostics['proc_open_available']) ? $yes : $no,
        '/usr/bin/ffmpeg exists' => !empty($diagnostics['ffmpeg_exists']) ? $yes : $no,
        '/usr/bin/ffmpeg executable' => !empty($diagnostics['ffmpeg_executable']) ? $yes : $no,
        '/usr/bin/ffprobe exists' => !empty($diagnostics['ffprobe_exists']) ? $yes : $no,
        '/usr/bin/ffprobe executable' => !empty($diagnostics['ffprobe_executable']) ? $yes : $no,
        'Temporary directory writable' => !empty($diagnostics['temp_writable']) ? $yes : $no,
        'open_basedir' => esc_html((string) ($diagnostics['open_basedir'] ?? 'Unknown')),
        'Temporary directory' => esc_html((string) ($diagnostics['temp_directory'] ?? 'Unknown')),
    ];

    if ($diagnostics['exec_exit_code'] !== null) {
        $rows['FFmpeg PHP test exit code'] = esc_html((string) $diagnostics['exec_exit_code']);
        $rows['FFmpeg PHP test response'] = esc_html((string) ($diagnostics['exec_first_line'] ?: 'No output returned'));
    }

    $html = '<details open style="max-width:900px;margin:0 auto 18px;background:#fff;border:1px solid #fecaca;border-radius:14px;padding:16px 18px;box-sizing:border-box;">';
    $html .= '<summary style="cursor:pointer;font-weight:800;color:#111827;font-size:16px;">FFmpeg Diagnostics</summary>';
    $html .= '<p style="margin:10px 0 14px;color:#6b7280;line-height:1.55;">Send this information to the VPS host. It checks the PHP process used by the website, not the root terminal.</p>';
    $html .= '<div style="display:grid;grid-template-columns:minmax(180px,.8fr) minmax(0,1.2fr);gap:0;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;">';
    foreach ($rows as $label => $value) {
        $html .= '<div style="padding:10px 12px;background:#f8fafc;border-bottom:1px solid #e5e7eb;font-weight:700;">' . esc_html($label) . '</div>';
        $html .= '<div style="padding:10px 12px;border-bottom:1px solid #e5e7eb;overflow-wrap:anywhere;">' . $value . '</div>';
    }
    $html .= '</div>';
    $html .= '<div style="margin-top:14px;padding:12px 14px;background:#fef2f2;border-radius:10px;color:#991b1b;line-height:1.55;"><strong>Host request:</strong> Enable <code>exec</code> for the website PHP process and ensure it can execute <code>/usr/bin/ffmpeg</code> and <code>/usr/bin/ffprobe</code>. If <code>open_basedir</code> is enabled, allow these binaries and the PHP temporary directory.</div>';
    $html .= '</details>';
    return $html;
}

public static function ffmpeg_path(){
    if (!function_exists('exec')) return '';

    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    if (in_array('exec', $disabled, true)) return '';

    $candidates = [
        '/usr/bin/ffmpeg',
        '/usr/local/bin/ffmpeg',
        'ffmpeg',
    ];

    foreach ($candidates as $candidate) {
        if ($candidate !== 'ffmpeg' && is_executable($candidate)) {
            return $candidate;
        }

        if ($candidate === 'ffmpeg') {
            $output = [];
            $code = 1;
            @exec('command -v ffmpeg 2>/dev/null', $output, $code);
            if ($code === 0 && !empty($output[0])) return trim($output[0]);
        }
    }

    return '';
}

private static function video_export_sidecar_path($token_hash){
    if (!preg_match('/^[a-f0-9]{64}$/', (string) $token_hash)) return '';
    return trailingslashit(get_temp_dir()) . 'surface-video-export-' . $token_hash . '.json';
}

public static function cleanup_stale_video_exports(){
    if (get_transient('surface_video_cleanup_lock')) return;
    set_transient('surface_video_cleanup_lock', 1, HOUR_IN_SECONDS);

    $pattern = trailingslashit(get_temp_dir()) . 'surface-video-*';
    $files = glob($pattern);
    if (!is_array($files)) return;

    $cutoff = time() - (2 * HOUR_IN_SECONDS);
    foreach ($files as $file) {
        if (is_file($file) && @filemtime($file) < $cutoff) {
            @unlink($file);
        }
    }
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
    if ($text === '') return new WP_Error('surface_mark_empty_text', 'The overlay text is empty.');
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

private static function video_diagnostic_update($product_id, $stage, $detail = ''){
    $user_id = get_current_user_id();
    if (!$user_id) return;
    $started = microtime(true);
    $existing = get_transient('surface_video_diag_' . (int) $user_id);
    if ($stage !== 'Starting' && is_array($existing) && !empty($existing['started_at'])) {
        $started = (float) $existing['started_at'];
    }
    $data = [
        'product_id' => (int) $product_id,
        'stage'      => sanitize_text_field($stage),
        'detail'     => sanitize_text_field($detail),
        'started_at' => $started,
        'updated_at' => microtime(true),
    ];
    set_transient('surface_video_diag_' . (int) $user_id, $data, 2 * HOUR_IN_SECONDS);
}

private static function get_video_diagnostic($user_id){
    $user_id = (int) $user_id;
    if (!$user_id) return [];
    $data = get_transient('surface_video_diag_' . $user_id);
    return is_array($data) ? $data : [];
}

private static function render_video_diagnostic($diag){
    $elapsed = '';
    if (!empty($diag['started_at']) && !empty($diag['updated_at'])) {
        $elapsed = number_format(max(0, (float)$diag['updated_at'] - (float)$diag['started_at']), 1) . 's elapsed';
    }
    $stage = isset($diag['stage']) ? $diag['stage'] : 'Unknown';
    $detail = isset($diag['detail']) ? $diag['detail'] : '';
    return '<div style="max-width:900px;margin:0 auto 14px;background:#f3f4f6;color:#111827;padding:12px;border-radius:8px;border:1px solid #d1d5db;box-sizing:border-box;"><div style="font-weight:800;margin-bottom:4px;">SurfaceMark Video Diagnostic</div><div><strong>Last stage:</strong> ' . esc_html($stage) . '</div>' . ($detail !== '' ? '<div style="margin-top:3px;"><strong>Detail:</strong> ' . esc_html($detail) . '</div>' : '') . ($elapsed !== '' ? '<div style="margin-top:3px;color:#4b5563;">' . esc_html($elapsed) . '</div>' : '') . '<div style="margin-top:6px;font-size:12px;color:#6b7280;">Diagnostic checkpoint only. It does not change the temporary Backblaze → VPS → download workflow.</div></div>';
}

public static function stamp_video($product_id, $surface_name, $job_id = ''){
    $job_data = $job_id !== '' ? get_transient('surface_video_job_' . sanitize_key($job_id)) : [];
    $settings = is_array($job_data) && is_array($job_data['settings'] ?? null) ? $job_data['settings'] : [];
    $mark_x = self::video_position($settings['mark_x'] ?? 4);
    $mark_y = self::video_position($settings['mark_y'] ?? 78);
    $ffmpeg = self::ffmpeg_path();
    self::video_diagnostic_update($product_id, 'Starting', 'Checking FFmpeg and video source.'); if ($job_id !== '') self::video_job_update($job_id, 'running', 'Starting', 'Checking FFmpeg and video source.');
    if ($ffmpeg === '') {
        return new WP_Error('surface_ffmpeg_missing', 'FFmpeg is not available to the website PHP process.');
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
    @ignore_user_abort(true);

    // Backblaze remains the permanent source. WordPress only receives a temporary copy.
    self::video_diagnostic_update($product_id, 'Backblaze download started', 'Waiting for the original video to reach the VPS.'); if ($job_id !== '') self::video_job_update($job_id, 'running', 'Backblaze download started', 'Waiting for the original video to reach the VPS.');
    $download_started = microtime(true);
    $input_path = download_url($video_url, 600);
    $download_elapsed = number_format(max(0, microtime(true) - $download_started), 1);
    if (!is_wp_error($input_path) && is_file($input_path)) {
        self::video_diagnostic_update($product_id, 'Backblaze download complete', $download_elapsed . 's; ' . number_format(filesize($input_path) / 1048576, 1) . ' MB temporary source.'); if ($job_id !== '') self::video_job_update($job_id, 'running', 'Backblaze download complete', $download_elapsed . 's; ' . number_format(filesize($input_path) / 1048576, 1) . ' MB temporary source.');
    }
    if (is_wp_error($input_path) || !is_file($input_path)) {
        return is_wp_error($input_path)
            ? $input_path
            : new WP_Error('surface_video_download_failed', 'The original Backblaze video could not be downloaded for stamping.');
    }

    self::video_diagnostic_update($product_id, 'Creating SurfaceMark', 'Generating the temporary mark image.'); if ($job_id !== '') self::video_job_update($job_id, 'running', 'Creating SurfaceMark', 'Generating the temporary mark image.');
    $mark_path = self::create_video_mark_png($sku);
    if (is_wp_error($mark_path)) {
        @unlink($input_path);
        return $mark_path;
    }
    $output_path = trailingslashit(get_temp_dir()) . 'surface-video-' . (int)$product_id . '-' . wp_generate_password(12, false, false) . '.mp4';

    // Only the selected SurfaceTooth's authorized SurfaceMark is overlaid; preserve the original video's audio.
    $filter = '[1:v]format=rgba[mark];[0:v][mark]overlay=x=(W-w)*' . ($mark_x / 100) . ':y=(H-h)*' . ($mark_y / 100) . ':format=auto[v]';
    $command = escapeshellarg($ffmpeg)
        . ' -hide_banner -loglevel error -y'
        . ' -i ' . escapeshellarg($input_path)
        . ' -loop 1 -framerate 25 -i ' . escapeshellarg($mark_path)
        . ' -filter_complex ' . escapeshellarg($filter)
        . ' -map ' . escapeshellarg('[v]')
        . ' -map 0:a?'
        . ' -c:v libx264 -preset veryfast -crf 23 -pix_fmt yuv420p'
        . ' -c:a aac -b:a 128k'
        . ' -movflags +faststart -shortest -f mp4 '
        . escapeshellarg($output_path)
        . ' 2>&1';

    $output = [];
    $exit_code = 1;
    self::video_diagnostic_update($product_id, 'FFmpeg started', 'Stamping video on the VPS.'); if ($job_id !== '') self::video_job_update($job_id, 'running', 'FFmpeg started', 'Stamping video on the VPS.');
    $ffmpeg_started = microtime(true);
    @exec($command, $output, $exit_code);
    $ffmpeg_elapsed = number_format(max(0, microtime(true) - $ffmpeg_started), 1);

    // Never retain the downloaded Backblaze source or temporary mark on the VPS.
    @unlink($input_path);
    @unlink($mark_path);

    if ($exit_code !== 0 || !is_file($output_path) || filesize($output_path) < 1024) {
        $detail = !empty($output) ? trim(implode(' | ', array_slice($output, -8))) : 'Unknown FFmpeg error.';
        if ($detail === '') $detail = 'FFmpeg did not produce a valid output file.';
        self::video_diagnostic_update($product_id, 'FFmpeg failed', $ffmpeg_elapsed . 's; exit code ' . (int)$exit_code . '. ' . $detail);
        if ($job_id !== '') self::video_job_update($job_id, 'failed', 'FFmpeg failed', $ffmpeg_elapsed . 's; exit code ' . (int)$exit_code . '. ' . $detail, ['error' => $detail]);
        @unlink($output_path);
        return new WP_Error('surface_ffmpeg_failed', 'Video stamping failed: ' . $detail);
    }

    $safe_surface = sanitize_file_name($surface_name ?: 'surface');
    $safe_sku = sanitize_file_name($sku ?: 'video');
    $filename = sanitize_file_name($safe_surface . '-' . $safe_sku . '.mp4');
    $token = wp_generate_password(48, false, false);
    $transient_key = 'surface_video_export_' . hash('sha256', $token);

    self::video_diagnostic_update($product_id, 'Stamped file ready', $ffmpeg_elapsed . 's FFmpeg time; ' . number_format(filesize($output_path) / 1048576, 1) . ' MB output.'); if ($job_id !== '') self::video_job_update($job_id, 'running', 'Stamped file ready', $ffmpeg_elapsed . 's FFmpeg time; ' . number_format(filesize($output_path) / 1048576, 1) . ' MB output.');

    $export_data = [
        'path'       => $output_path,
        'filename'   => $filename,
        'product_id' => (int) $product_id,
        'user_id'    => (int) get_current_user_id(),
        'created_at' => time(),
    ];

    // Do not report a successful handoff unless WordPress can immediately
    // retrieve the temporary export token it just stored.
    $transient_saved = set_transient($transient_key, $export_data, 30 * MINUTE_IN_SECONDS);
    $transient_verified = is_array(get_transient($transient_key));
    $sidecar_path = self::video_export_sidecar_path(hash('sha256', $token));
    $sidecar_saved = $sidecar_path !== ''
        && @file_put_contents($sidecar_path, wp_json_encode($export_data), LOCK_EX) !== false;
    @chmod($sidecar_path, 0600);
    $sidecar_verified = $sidecar_saved
        && is_readable($sidecar_path)
        && is_array(json_decode((string) @file_get_contents($sidecar_path), true));
    if ((!$transient_verified && !$sidecar_verified) || (!$transient_saved && !$sidecar_verified)) {
        delete_transient($transient_key);
        if ($sidecar_path !== '') @unlink($sidecar_path);
        @unlink($output_path);
        self::video_diagnostic_update($product_id, 'Download handoff failed', 'The temporary stamped file was created, but neither the transient nor fallback export record could be verified.'); if ($job_id !== '') self::video_job_update($job_id, 'failed', 'Download handoff failed', 'The temporary stamped file was created, but neither export record could be verified.', ['error'=>'Download handoff failed.']);
        return new WP_Error('surface_video_export_store_failed', 'The surfaced video was created, but its download handoff could not be prepared. Please try again.');
    }

    self::video_diagnostic_update($product_id, 'Download URL ready', 'Temporary stamped file is waiting for browser download.');

    // Stamped exports are no longer permanent media records. The original Backblaze video is untouched.
    delete_post_meta($product_id, $ready_meta);
    delete_post_meta($product_id, $ready_key_meta);
    delete_post_meta($product_id, $ready_id_meta);

    /*
     * This URL is consumed by JavaScript for automatic download.
     * Do not use wp_nonce_url() here: it HTML-escapes query separators to
     * &amp;, which is correct for HTML attributes but breaks the raw URL when
     * assigned to window.location.href. Add the nonce as a normal query arg.
     */
    $download_url = add_query_arg([
        'action'              => 'surface_download_video',
        'product_id'          => (int) $product_id,
        'surface_video_token' => $token,
        '_wpnonce'            => wp_create_nonce(
            'surface_download_video_' . (int) $product_id
        ),
    ], admin_url('admin-post.php'));

    if ($job_id !== '') self::video_job_update($job_id, 'complete', 'Download URL ready', 'Temporary stamped SurfaceMark video is ready.', ['download_url'=>$download_url,'filename'=>$filename]);

    return [
        'download_url' => $download_url,
        'filename'     => $filename,
        'temporary'    => true,
    ];
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










