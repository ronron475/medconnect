package ph.bagocity.medconnect;

import android.Manifest;
import android.annotation.SuppressLint;
import android.app.Activity;
import android.content.ActivityNotFoundException;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.net.Uri;
import android.os.Bundle;
import android.view.View;
import android.webkit.PermissionRequest;
import android.webkit.SslErrorHandler;
import android.webkit.ValueCallback;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.Button;
import android.widget.TextView;

import androidx.activity.result.ActivityResultLauncher;
import androidx.activity.result.contract.ActivityResultContracts;
import androidx.annotation.NonNull;
import androidx.annotation.Nullable;
import androidx.appcompat.app.AppCompatActivity;
import androidx.core.app.ActivityCompat;
import androidx.core.content.ContextCompat;

import java.util.ArrayList;
import java.util.Locale;

public class MainActivity extends AppCompatActivity {
    private static final String PORTAL_URL = "https://medconnect.bccbsis.com/";
    private static final String TRUSTED_HOST = "medconnect.bccbsis.com";
    private static final int MEDIA_PERMISSION_CODE = 42;

    private WebView webView;
    private View errorPanel;
    private TextView errorMessage;
    private PermissionRequest pendingWebPermission;
    private String[] pendingMediaResources;
    private ValueCallback<Uri[]> filePathCallback;
    private String lastFailedUrl = PORTAL_URL;

    private final ActivityResultLauncher<Intent> fileChooserLauncher =
            registerForActivityResult(new ActivityResultContracts.StartActivityForResult(), result -> {
                ValueCallback<Uri[]> callback = filePathCallback;
                filePathCallback = null;
                if (callback == null) {
                    return;
                }
                Uri[] uris = null;
                if (result.getResultCode() == Activity.RESULT_OK && result.getData() != null) {
                    uris = WebChromeClient.FileChooserParams.parseResult(
                            result.getResultCode(),
                            result.getData()
                    );
                }
                // Cancel or empty selection must deliver null so the WebView form can continue.
                callback.onReceiveValue(uris);
            });

    @SuppressLint("SetJavaScriptEnabled")
    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_main);

        webView = findViewById(R.id.webView);
        errorPanel = findViewById(R.id.errorPanel);
        errorMessage = findViewById(R.id.errorMessage);
        Button errorRetry = findViewById(R.id.errorRetry);
        errorRetry.setOnClickListener(v -> retryLoad());

        WebSettings settings = webView.getSettings();
        settings.setJavaScriptEnabled(true);
        settings.setDomStorageEnabled(true);
        settings.setDatabaseEnabled(true);
        settings.setMediaPlaybackRequiresUserGesture(false);
        // Block file:// ; allow content:// so National ID picker uploads can be read.
        settings.setAllowFileAccess(false);
        settings.setAllowContentAccess(true);
        settings.setMixedContentMode(WebSettings.MIXED_CONTENT_NEVER_ALLOW);

        webView.setWebViewClient(new PortalWebViewClient());
        webView.setWebChromeClient(new PortalWebChromeClient());

        webView.loadUrl(PORTAL_URL);
    }

    private void retryLoad() {
        hideError();
        String target = (lastFailedUrl != null && isTrustedUrl(lastFailedUrl))
                ? lastFailedUrl
                : PORTAL_URL;
        webView.loadUrl(target);
    }

    private void showError(@Nullable String message) {
        if (errorMessage != null) {
            errorMessage.setText(
                    message != null && !message.isEmpty()
                            ? message
                            : getString(R.string.error_message)
            );
        }
        if (errorPanel != null) {
            errorPanel.setVisibility(View.VISIBLE);
        }
        if (webView != null) {
            webView.setVisibility(View.INVISIBLE);
        }
    }

    private void hideError() {
        if (errorPanel != null) {
            errorPanel.setVisibility(View.GONE);
        }
        if (webView != null) {
            webView.setVisibility(View.VISIBLE);
        }
    }

    private static boolean isTrustedHost(@Nullable String host) {
        if (host == null || host.isEmpty()) {
            return false;
        }
        String h = host.toLowerCase(Locale.US);
        return h.equals(TRUSTED_HOST) || h.endsWith("." + TRUSTED_HOST);
    }

    private static boolean isTrustedUrl(@Nullable String url) {
        if (url == null || url.isEmpty()) {
            return false;
        }
        Uri uri = Uri.parse(url);
        if (!"https".equalsIgnoreCase(uri.getScheme())) {
            return false;
        }
        return isTrustedHost(uri.getHost());
    }

    private static boolean isTrustedOrigin(@Nullable Uri origin) {
        if (origin == null) {
            return false;
        }
        return isTrustedUrl(origin.toString());
    }

    private void openExternal(@NonNull Uri uri) {
        try {
            Intent intent = new Intent(Intent.ACTION_VIEW, uri);
            intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
            startActivity(intent);
        } catch (ActivityNotFoundException ignored) {
            // No handler available; leave the WebView on the current page.
        }
    }

    private void cancelFileChooser() {
        if (filePathCallback != null) {
            filePathCallback.onReceiveValue(null);
            filePathCallback = null;
        }
    }

    private void handleWebPermission(PermissionRequest request) {
        if (!isTrustedOrigin(request.getOrigin())) {
            request.deny();
            return;
        }

        ArrayList<String> mediaResources = new ArrayList<>();
        boolean needCamera = false;
        boolean needMic = false;
        for (String resource : request.getResources()) {
            if (PermissionRequest.RESOURCE_VIDEO_CAPTURE.equals(resource)) {
                needCamera = true;
                mediaResources.add(resource);
            } else if (PermissionRequest.RESOURCE_AUDIO_CAPTURE.equals(resource)) {
                needMic = true;
                mediaResources.add(resource);
            }
        }

        if (mediaResources.isEmpty()) {
            request.deny();
            return;
        }

        pendingWebPermission = request;
        pendingMediaResources = mediaResources.toArray(new String[0]);

        boolean cameraOk = !needCamera
                || ContextCompat.checkSelfPermission(this, Manifest.permission.CAMERA)
                == PackageManager.PERMISSION_GRANTED;
        boolean micOk = !needMic
                || ContextCompat.checkSelfPermission(this, Manifest.permission.RECORD_AUDIO)
                == PackageManager.PERMISSION_GRANTED;

        if (cameraOk && micOk) {
            grantPending();
            return;
        }

        ArrayList<String> needed = new ArrayList<>();
        if (needCamera && !cameraOk) needed.add(Manifest.permission.CAMERA);
        if (needMic && !micOk) needed.add(Manifest.permission.RECORD_AUDIO);
        ActivityCompat.requestPermissions(this, needed.toArray(new String[0]), MEDIA_PERMISSION_CODE);
    }

    private void grantPending() {
        if (pendingWebPermission == null) return;
        if (!isTrustedOrigin(pendingWebPermission.getOrigin())
                || pendingMediaResources == null
                || pendingMediaResources.length == 0) {
            pendingWebPermission.deny();
            pendingWebPermission = null;
            pendingMediaResources = null;
            return;
        }
        pendingWebPermission.grant(pendingMediaResources);
        pendingWebPermission = null;
        pendingMediaResources = null;
    }

    @Override
    public void onRequestPermissionsResult(int requestCode, @NonNull String[] permissions, @NonNull int[] grantResults) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults);
        if (requestCode != MEDIA_PERMISSION_CODE || pendingWebPermission == null) return;

        boolean granted = grantResults.length > 0;
        for (int result : grantResults) {
            if (result != PackageManager.PERMISSION_GRANTED) {
                granted = false;
                break;
            }
        }
        if (granted) {
            grantPending();
        } else {
            pendingWebPermission.deny();
            pendingWebPermission = null;
            pendingMediaResources = null;
        }
    }

    @Override
    public void onBackPressed() {
        if (errorPanel != null && errorPanel.getVisibility() == View.VISIBLE) {
            finish();
            return;
        }
        if (webView != null && webView.canGoBack()) {
            webView.goBack();
            return;
        }
        super.onBackPressed();
    }

    @Override
    protected void onDestroy() {
        cancelFileChooser();
        if (pendingWebPermission != null) {
            pendingWebPermission.deny();
            pendingWebPermission = null;
            pendingMediaResources = null;
        }
        super.onDestroy();
    }

    private final class PortalWebViewClient extends WebViewClient {
        @Override
        public boolean shouldOverrideUrlLoading(@NonNull WebView view, @NonNull WebResourceRequest request) {
            Uri uri = request.getUrl();
            if (uri == null) {
                return true;
            }
            String scheme = uri.getScheme() != null ? uri.getScheme().toLowerCase(Locale.US) : "";
            // In-document schemes used by the portal (modals, blob downloads, blank targets).
            if ("about".equals(scheme) || "blob".equals(scheme) || "data".equals(scheme)) {
                return false;
            }
            if ("https".equals(scheme) && isTrustedHost(uri.getHost())) {
                return false;
            }
            if ("http".equals(scheme) || "https".equals(scheme) || "mailto".equals(scheme) || "tel".equals(scheme)) {
                openExternal(uri);
            }
            return true;
        }

        @Override
        public void onPageFinished(WebView view, String url) {
            if (isTrustedUrl(url)) {
                hideError();
            }
        }

        @Override
        public void onReceivedError(WebView view, WebResourceRequest request, WebResourceError error) {
            if (request == null || !request.isForMainFrame()) {
                return;
            }
            String failing = request.getUrl() != null ? request.getUrl().toString() : PORTAL_URL;
            lastFailedUrl = isTrustedUrl(failing) ? failing : PORTAL_URL;
            showError(getString(R.string.error_message));
        }

        @Override
        public void onReceivedSslError(WebView view, SslErrorHandler handler, android.net.http.SslError error) {
            // Never proceed on certificate errors.
            handler.cancel();
            lastFailedUrl = PORTAL_URL;
            showError(getString(R.string.error_ssl));
        }
    }

    private final class PortalWebChromeClient extends WebChromeClient {
        @Override
        public void onPermissionRequest(PermissionRequest request) {
            runOnUiThread(() -> handleWebPermission(request));
        }

        @Override
        public boolean onShowFileChooser(
                WebView webView,
                ValueCallback<Uri[]> filePathCallback,
                FileChooserParams fileChooserParams
        ) {
            cancelFileChooser();
            MainActivity.this.filePathCallback = filePathCallback;
            Intent intent;
            try {
                intent = fileChooserParams.createIntent();
            } catch (Exception e) {
                cancelFileChooser();
                return false;
            }
            try {
                fileChooserLauncher.launch(intent);
                return true;
            } catch (ActivityNotFoundException e) {
                cancelFileChooser();
                return false;
            }
        }
    }
}
