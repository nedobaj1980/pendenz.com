from PIL import Image, ImageFilter

def process():
    # Load base
    base_img = Image.open('assets/img/pdf_report_preview_base.png').convert('RGBA')
    
    # Load logo
    logo = Image.open('assets/img/logo_pendenz_official.png').convert('RGBA')
    
    # Resize logo
    target_size = (140, 140)
    logo = logo.resize(target_size, Image.Resampling.LANCZOS)
    
    # Rotate logo
    logo = logo.rotate(-2, expand=True, fillcolor=(255,255,255,0))
    
    # Crop a blank piece of paper to cover the old logo
    # Let's take a patch from the bottom area of the paper which is mostly blank
    # Or just grab the area right below the old logo (y=250 to 350)
    # The old logo is roughly x=540 to 680, y=110 to 240.
    # Let's crop a patch from x=540 to 680, y=250 to 380 (this has some table lines though)
    # Better: let's just create a patch with the exact paper color. 
    # The paper is not pure white. Let's sample a pixel near the logo.
    paper_color = base_img.getpixel((500, 150))
    
    # Create a patch of that color
    patch = Image.new('RGBA', (150, 140), paper_color)
    
    # We will paste this patch over the old logo: x=535, y=110
    base_img.paste(patch, (535, 110), patch)
    
    # Now paste the new logo
    paste_pos = (540, 110)
    base_img.paste(logo, paste_pos, logo)
    
    # Save
    base_img.convert('RGB').save('assets/img/pdf_report_preview.png', quality=95)

if __name__ == '__main__':
    process()
    print("Done")
