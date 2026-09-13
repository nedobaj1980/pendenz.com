from PIL import Image, ImageDraw

def process():
    # Load base
    base_img = Image.open('assets/img/pdf_report_preview_base.png').convert('RGBA')
    
    # Load logo
    logo = Image.open('assets/img/logo_pendenz_official.png').convert('RGBA')
    
    # The old logo in the generated image is roughly around x=650-800, y=100-200.
    # We want to paste the new logo there.
    # First, let's make the logo proportional. It's a square logo.
    target_size = (180, 180)
    logo = logo.resize(target_size, Image.Resampling.LANCZOS)
    
    # Rotate logo to match sheet perspective
    logo = logo.rotate(-3, expand=True, fillcolor=(255,255,255,0))
    
    # Let's draw a white patch to cover the old logo, just in case.
    # Using a soft off-white to match paper
    draw = ImageDraw.Draw(base_img)
    patch_rect = [680, 100, 850, 240]
    draw.rectangle(patch_rect, fill=(240, 240, 240, 255))
    
    # Paste logo. Paste coordinate is top-left
    paste_pos = (680, 105)
    base_img.paste(logo, paste_pos, logo)
    
    # Save as RGB to remove alpha channel for final png
    base_img.convert('RGB').save('assets/img/pdf_report_preview.png', quality=95)

if __name__ == '__main__':
    process()
    print("Done")
