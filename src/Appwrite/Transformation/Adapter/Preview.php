<?php

namespace Appwrite\Transformation\Adapter;

use Appwrite\Transformation\Adapter;

class Preview extends Adapter
{
    /**
     * @param array<mixed> $traits Proxied response headers
     */
    public function isValid(array $traits): bool
    {
        $contentType = '';

        foreach ($traits as $key => $value) {
            if (\strtolower($key) === 'content-type') {
                $contentType = $value;
                break;
            }
        }

        if (\str_contains($contentType, 'text/html')) {
            return true;
        }

        return false;
    }

    public function transform(): void
    {
        $this->output = $this->input;

        $banner = <<<EOT
        <link rel="preconnect" href="https://assets.appwrite.io/" crossorigin>
        <style>
            @font-face {
                font-family: 'Inter';
                src: url('https://assets.appwrite.io/fonts/inter/Inter-Regular.woff2') format('woff2');
                font-weight: 400;
                font-style: normal;
                font-display: swap;
            }
            @font-face {
                font-family: 'Inter';
                src: url('https://assets.appwrite.io/fonts/inter/Inter-Medium.woff2') format('woff2');
                font-weight: 500;
                font-style: normal;
                font-display: swap;
            }
        </style>
        <style>
            #appwrite-preview {
                min-width: auto;
                min-height: auto;
                max-width: none;
                max-height: none;
                width: auto;
                height: auto;
                padding: 0;
                margin: 0;
                position: fixed;
                right: 16px;
                bottom: 16px;
                z-index: calc(infinity);
                border-radius: var(--border-radius-S, 8px);
                border: var(--border-width-S, 1px) solid var(--color-border-neutral, #EDEDF0);
                background: var(--color-bgColor-neutral-primary, #FFF);
                box-shadow: 0px 1px 3px 0px rgba(0, 0, 0, 0.03), 0px 4px 4px 0px rgba(0, 0, 0, 0.04);
                padding: var(--space-3, 6px) var(--space-4, 8px);
                display: flex;
                justify-content: center;
                align-items: center;
                gap: var(--gap-XXS, 4px);
                cursor: pointer;
                transition: opacity 0.3s;
            }

            #appwrite-preview-close {
                position: absolute;
                right: 0px;
                bottom: 0px;
                border-radius: var(--border-radius-S, 8px);
                background: linear-gradient(270deg, #FFF 69.64%, rgba(255, 255, 255, 0.00) 114.29%);
                height: 100%;
                aspect-ratio: 1 / 1;
                display: flex;
                justify-content: center;
                align-items: center;
                opacity: 0;
                transition: opacity 0.3s;
            }

            #appwrite-preview-logo-dark {
                display: none;
            }

            #appwrite-preview:hover #appwrite-preview-close {
                opacity: 1;
            }

            #appwrite-preview-text {
                padding: 0;
                margin: 0;
                color: var(--color-fgColor-neutral-secondary, #56565C);
                font-family: var(--font-family-sansSerif, Inter), sans-serif;
                font-size: var(--font-size-XS, 12px);
                font-style: normal;
                font-weight: 500;
                line-height: 130%;
                letter-spacing: -0.12px;
            }

            #appwrite-preview-close-text {
                opacity: 0;
                transition: opacity 0.3s;
                position: absolute;
                bottom: calc(15px + 4px);
                display: flex;
                padding: var(--space-1, 2px) var(--space-2, 4px);
                color: var(--color-fgColor-neutral-secondary, #56565C);
                text-align: center;
                font-family: var(--font-family-sansSerif, Inter), sans-serif;
                font-size: var(--font-size-XS, 12px);
                font-style: normal;
                font-weight: 400;
                line-height: 130%;
                letter-spacing: -0.12px;
                border-radius: var(--border-radius-XS, 6px);
                background: #EDEDF0;
            }

            #appwrite-preview-close:hover #appwrite-preview-close-text {
                opacity: 1;
            }   

            @media (prefers-color-scheme: dark) {
                #appwrite-preview {
                    border: var(--border-width-S, 1px) solid var(--color-border-neutral, #2D2D31);
                    background: var(--color-bgColor-neutral-primary, #1D1D21);
                    box-shadow: 0px 1px 3px 0px rgba(0, 0, 0, 0.03), 0px 4px 4px 0px rgba(0, 0, 0, 0.04);
                }

                #appwrite-preview-text {
                color: var(--color-fgColor-neutral-secondary, #C3C3C6);
                    font-family: var(--font-family-sansSerif, Inter), sans-serif;
                    font-size: var(--font-size-XS, 12px);
                }

                #appwrite-preview-logo-dark {
                    display: block;
                }

                #appwrite-preview-logo-light {
                    display: none;
                }

                #appwrite-preview-close {
                    background: linear-gradient(270deg, #1D1D21 69.64%, rgba(29, 29, 33, 0.00) 114.29%);
                }

                #appwrite-preview-close-text {
                    background: #2D2D31;
                    color: var(--color-fgColor-neutral-secondary, #C3C3C6);
                }
            }
        </style>

        <button id="appwrite-preview">
            <p id="appwrite-preview-text">Preview by</p>

                <div id="appwrite-preview-close">
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path fill-rule="evenodd" clip-rule="evenodd" d="M3.43451 3.43439C3.74693 3.12197 4.25346 3.12197 4.56588 3.43439L8.0002 6.8687L11.4345 3.43439C11.7469 3.12197 12.2535 3.12197 12.5659 3.43439C12.8783 3.74681 12.8783 4.25334 12.5659 4.56576L9.13157 8.00007L12.5659 11.4344C12.8783 11.7468 12.8783 12.2533 12.5659 12.5658C12.2535 12.8782 11.7469 12.8782 11.4345 12.5658L8.0002 9.13144L4.56588 12.5658C4.25346 12.8782 3.74693 12.8782 3.43451 12.5658C3.12209 12.2533 3.12209 11.7468 3.43451 11.4344L6.86882 8.00007L3.43451 4.56576C3.12209 4.25334 3.12209 3.74681 3.43451 3.43439Z" fill="#97979B"/>
                    </svg>

                    <p id="appwrite-preview-close-text">Hide</p>
                </div>

                <svg id="appwrite-preview-logo-light" width="65" height="12" viewBox="0 0 65 12" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M18.9862 9.74762C20.0493 9.74762 20.5867 9.191 20.8204 8.81202H20.9255C20.9722 9.21468 21.2526 9.59366 21.8017 9.59366H22.8414V8.40936H22.5727C22.3858 8.40936 22.2924 8.30277 22.2924 8.13697V3.38795H20.9138V4.1459H20.8087C20.54 3.76692 19.9792 3.23399 18.9511 3.23399C17.3156 3.23399 16.1006 4.60777 16.1006 6.4908C16.1006 8.37383 17.3389 9.74762 18.9862 9.74762ZM19.2315 8.39752C18.2619 8.39752 17.5025 7.6751 17.5025 6.50265C17.5025 5.35388 18.2385 4.57224 19.2198 4.57224C20.1544 4.57224 20.9372 5.27098 20.9372 6.50265C20.9372 7.55667 20.2713 8.39752 19.2315 8.39752Z" fill="#2D2D31"/>
                    <path d="M23.6553 12H25.0339V8.81202H25.139C25.396 9.191 25.9451 9.74762 27.0316 9.74762C28.6672 9.74762 29.8588 8.35015 29.8588 6.4908C29.8588 4.61962 28.5854 3.23399 26.9381 3.23399C25.8867 3.23399 25.3727 3.81429 25.1273 4.13405H25.0222V3.38795H23.6553V12ZM26.7395 8.43305C25.7933 8.43305 25.0105 7.72247 25.0105 6.4908C25.0105 5.43678 25.6764 4.54856 26.7162 4.54856C27.6858 4.54856 28.4452 5.31835 28.4452 6.4908C28.4452 7.63957 27.7092 8.43305 26.7395 8.43305Z" fill="#2D2D31"/>
                    <path d="M30.5701 12H31.9487V8.81202H32.0538C32.3108 9.191 32.8599 9.74762 33.9464 9.74762C35.582 9.74762 36.66 8.35015 36.66 6.4908C36.66 4.61962 35.5002 3.23399 33.8529 3.23399C32.8015 3.23399 32.2875 3.81429 32.0421 4.13405H31.937V3.38795H30.5701V12ZM33.6543 8.43305C32.708 8.43305 31.9253 7.72247 31.9253 6.4908C31.9253 5.43678 32.5912 4.54856 33.631 4.54856C34.6006 4.54856 35.36 5.31835 35.36 6.4908C35.36 7.63957 34.624 8.43305 33.6543 8.43305Z" fill="#2D2D31"/>
                    <path d="M38.4823 9.73776H40.4333L41.5431 4.87031H41.6132L42.7231 9.73776H44.6624L46.2153 3.53205H44.8259L43.7161 8.41135H43.6109L42.5011 3.53205H40.6669L39.5454 8.41135H39.4403L38.3421 3.53205H36.8701L38.4823 9.73776Z" fill="#2D2D31"/>
                    <path d="M46.9137 9.73776H48.2923V6.67044C48.2923 5.49798 48.8297 4.77556 49.8344 4.77556H50.4418V3.37809H49.9862C49.2035 3.37809 48.6077 3.92287 48.374 4.44396H48.2806V3.53205H46.9137V9.73776Z" fill="#2D2D31"/>
                    <path d="M57.2829 9.73776H58.3577V8.49425H57.2946C56.874 8.49425 56.6988 8.30476 56.6988 7.86657V4.76372H58.4278V3.53205H56.6988V1.79114H55.3903V3.53205H54.2454V4.76372H55.3085V7.87842C55.3085 9.19299 56.0913 9.73776 57.2829 9.73776Z" fill="#2D2D31"/>
                    <path d="M62.0561 9.74762C63.3295 9.74762 64.451 9.1081 64.8482 7.81721L63.5865 7.5093C63.3645 8.19619 62.722 8.55148 62.0444 8.55148C61.0397 8.55148 60.3738 7.88827 60.3621 6.84609H65.0001V6.45527C65.0001 4.60777 63.8669 3.23399 61.9977 3.23399C60.3504 3.23399 58.9368 4.54856 58.9368 6.50265C58.9368 8.39752 60.1869 9.74762 62.0561 9.74762ZM60.3738 5.8276C60.4556 5.08149 61.1215 4.45381 61.9977 4.45381C62.8388 4.45381 63.5281 4.98675 63.5982 5.8276H60.3738Z" fill="#2D2D31"/>
                    <path fill-rule="evenodd" clip-rule="evenodd" d="M53.6325 9.73776H52.2539V4.76372H51.1791V3.53205H53.6325V9.73776Z" fill="#2D2D31"/>
                    <path d="M52.841 2.67085C53.3434 2.67085 53.7172 2.29187 53.7172 1.79447C53.7172 1.30891 53.3434 0.929932 52.841 0.929932C52.3387 0.929932 51.9648 1.30891 51.9648 1.79447C51.9648 2.29187 52.3387 2.67085 52.841 2.67085Z" fill="#2D2D31"/>
                    <path d="M12.0363 8.21609V10.9548H5.29451C3.33035 10.9548 1.61537 9.85334 0.697814 8.21609C0.564426 7.97807 0.447681 7.72836 0.349751 7.46917C0.157509 6.96127 0.0366636 6.41627 0 5.84762V5.10717C0.00795985 4.98044 0.0205026 4.85471 0.0369048 4.73048C0.0704326 4.47553 0.121086 4.22606 0.18766 3.98356C0.817453 1.68455 2.86531 0 5.29451 0C7.72371 0 9.77132 1.68455 10.4011 3.98356H7.51844C7.04519 3.23415 6.22605 2.7387 5.29451 2.7387C4.36296 2.7387 3.54382 3.23415 3.07057 3.98356C2.92633 4.21137 2.81441 4.46258 2.74108 4.73048C2.67596 4.968 2.64122 5.21846 2.64122 5.47739C2.64122 6.2624 2.96106 6.96999 3.47387 7.46917C3.94905 7.93251 4.5897 8.21609 5.29451 8.21609H12.0363Z" fill="#FD366E"/>
                    <path d="M12.0364 4.73047V7.46917H7.11523C7.62804 6.96998 7.94788 6.2624 7.94788 5.47739C7.94788 5.21846 7.91315 4.96799 7.84802 4.73047H12.0364Z" fill="#FD366E"/>
                </svg>

                <svg id="appwrite-preview-logo-dark" width="65" height="12" viewBox="0 0 65 12" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M18.9862 9.74762C20.0493 9.74762 20.5867 9.191 20.8204 8.81202H20.9255C20.9722 9.21468 21.2526 9.59366 21.8017 9.59366H22.8414V8.40936H22.5727C22.3858 8.40936 22.2924 8.30277 22.2924 8.13697V3.38795H20.9138V4.1459H20.8087C20.54 3.76692 19.9792 3.23399 18.9511 3.23399C17.3156 3.23399 16.1006 4.60777 16.1006 6.4908C16.1006 8.37383 17.3389 9.74762 18.9862 9.74762ZM19.2315 8.39752C18.2619 8.39752 17.5025 7.6751 17.5025 6.50265C17.5025 5.35388 18.2385 4.57224 19.2198 4.57224C20.1544 4.57224 20.9372 5.27098 20.9372 6.50265C20.9372 7.55667 20.2713 8.39752 19.2315 8.39752Z" fill="#EDEDF0"/>
                    <path d="M23.6553 12H25.0339V8.81202H25.139C25.396 9.191 25.9451 9.74762 27.0316 9.74762C28.6672 9.74762 29.8588 8.35015 29.8588 6.4908C29.8588 4.61962 28.5854 3.23399 26.9381 3.23399C25.8867 3.23399 25.3727 3.81429 25.1273 4.13405H25.0222V3.38795H23.6553V12ZM26.7395 8.43305C25.7933 8.43305 25.0105 7.72247 25.0105 6.4908C25.0105 5.43678 25.6764 4.54856 26.7162 4.54856C27.6858 4.54856 28.4452 5.31835 28.4452 6.4908C28.4452 7.63957 27.7092 8.43305 26.7395 8.43305Z" fill="#EDEDF0"/>
                    <path d="M30.5701 12H31.9487V8.81202H32.0538C32.3108 9.191 32.8599 9.74762 33.9464 9.74762C35.582 9.74762 36.66 8.35015 36.66 6.4908C36.66 4.61962 35.5002 3.23399 33.8529 3.23399C32.8015 3.23399 32.2875 3.81429 32.0421 4.13405H31.937V3.38795H30.5701V12ZM33.6543 8.43305C32.708 8.43305 31.9253 7.72247 31.9253 6.4908C31.9253 5.43678 32.5912 4.54856 33.631 4.54856C34.6006 4.54856 35.36 5.31835 35.36 6.4908C35.36 7.63957 34.624 8.43305 33.6543 8.43305Z" fill="#EDEDF0"/>
                    <path d="M38.4823 9.73776H40.4333L41.5431 4.87031H41.6132L42.7231 9.73776H44.6624L46.2153 3.53205H44.8259L43.7161 8.41135H43.6109L42.5011 3.53205H40.6669L39.5454 8.41135H39.4403L38.3421 3.53205H36.8701L38.4823 9.73776Z" fill="#EDEDF0"/>
                    <path d="M46.9137 9.73776H48.2923V6.67044C48.2923 5.49798 48.8297 4.77556 49.8344 4.77556H50.4418V3.37809H49.9862C49.2035 3.37809 48.6077 3.92287 48.374 4.44396H48.2806V3.53205H46.9137V9.73776Z" fill="#EDEDF0"/>
                    <path d="M57.2829 9.73776H58.3577V8.49425H57.2946C56.874 8.49425 56.6988 8.30476 56.6988 7.86657V4.76372H58.4278V3.53205H56.6988V1.79114H55.3903V3.53205H54.2454V4.76372H55.3085V7.87842C55.3085 9.19299 56.0913 9.73776 57.2829 9.73776Z" fill="#EDEDF0"/>
                    <path d="M62.0561 9.74762C63.3295 9.74762 64.451 9.1081 64.8482 7.81721L63.5865 7.5093C63.3645 8.19619 62.722 8.55148 62.0444 8.55148C61.0397 8.55148 60.3738 7.88827 60.3621 6.84609H65.0001V6.45527C65.0001 4.60777 63.8669 3.23399 61.9977 3.23399C60.3504 3.23399 58.9368 4.54856 58.9368 6.50265C58.9368 8.39752 60.1869 9.74762 62.0561 9.74762ZM60.3738 5.8276C60.4556 5.08149 61.1215 4.45381 61.9977 4.45381C62.8388 4.45381 63.5281 4.98675 63.5982 5.8276H60.3738Z" fill="#EDEDF0"/>
                    <path fill-rule="evenodd" clip-rule="evenodd" d="M53.6325 9.73776H52.2539V4.76372H51.1791V3.53205H53.6325V9.73776Z" fill="#EDEDF0"/>
                    <path d="M52.841 2.67085C53.3434 2.67085 53.7172 2.29187 53.7172 1.79447C53.7172 1.30891 53.3434 0.929932 52.841 0.929932C52.3387 0.929932 51.9648 1.30891 51.9648 1.79447C51.9648 2.29187 52.3387 2.67085 52.841 2.67085Z" fill="#EDEDF0"/>
                    <path d="M12.0363 8.21609V10.9548H5.29451C3.33035 10.9548 1.61537 9.85334 0.697814 8.21609C0.564426 7.97807 0.447681 7.72836 0.349751 7.46917C0.157509 6.96127 0.0366636 6.41627 0 5.84762V5.10717C0.00795985 4.98044 0.0205026 4.85471 0.0369048 4.73048C0.0704326 4.47553 0.121086 4.22606 0.18766 3.98356C0.817453 1.68455 2.86531 0 5.29451 0C7.72371 0 9.77132 1.68455 10.4011 3.98356H7.51844C7.04519 3.23415 6.22605 2.7387 5.29451 2.7387C4.36296 2.7387 3.54382 3.23415 3.07057 3.98356C2.92633 4.21137 2.81441 4.46258 2.74108 4.73048C2.67596 4.968 2.64122 5.21846 2.64122 5.47739C2.64122 6.2624 2.96106 6.96999 3.47387 7.46917C3.94905 7.93251 4.5897 8.21609 5.29451 8.21609H12.0363Z" fill="#FD366E"/>
                    <path d="M12.0364 4.73047V7.46917H7.11523C7.62804 6.96998 7.94788 6.2624 7.94788 5.47739C7.94788 5.21846 7.91315 4.96799 7.84802 4.73047H12.0364Z" fill="#FD366E"/>
                </svg>
        </button>

        <script>
            (function() {
                var banner = document.getElementById("appwrite-preview");
                banner.addEventListener("click", function() {
                    banner.style.opacity = 0;
                    setTimeout(() => {
                        banner.style.display = 'none';
                    }, 350);
                });
            })();
        </script>
        EOT;

        $this->output .= $banner;
    }
}
