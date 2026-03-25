#!/usr/bin/env python3
import json
import sys

try:
    import cv2
except Exception as exc:
    print(json.dumps({"ok": False, "error": f"OpenCV import failed: {exc}"}))
    sys.exit(2)


def decode_image(path: str):
    img = cv2.imread(path)
    if img is None:
        return None

    detector = cv2.QRCodeDetector()
    passes = []

    variants = [
        ("original", img),
        ("grayscale", cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)),
    ]

    gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
    _, thresh = cv2.threshold(gray, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)
    variants.append(("threshold", thresh))

    for scale in (1.0, 1.5, 2.0, 0.75):
        resized = cv2.resize(gray, None, fx=scale, fy=scale, interpolation=cv2.INTER_CUBIC if scale >= 1 else cv2.INTER_AREA)
        variants.append((f"gray_{scale}x", resized))

    for label, variant in variants:
        try:
            text, points, _ = detector.detectAndDecode(variant)
            passes.append({"label": label, "ok": bool(text)})
            if text:
                return {"text": text, "passes": passes}
        except Exception:
            passes.append({"label": label, "ok": False})

    return {"text": "", "passes": passes}


if __name__ == "__main__":
    if len(sys.argv) < 2:
        print(json.dumps({"ok": False, "error": "Missing file path"}))
        sys.exit(1)

    result = decode_image(sys.argv[1])
    if result is None:
        print(json.dumps({"ok": False, "error": "Image could not be loaded"}))
        sys.exit(1)

    print(json.dumps({"ok": True, "text": result.get("text", ""), "passes": result.get("passes", [])}))
