These two MP4 files are synthetic purple 16×16 clips generated with ffmpeg and libx264. They contain no customer media or external recordings.

```
ffmpeg -hide_banner -loglevel error -f lavfi -i color=c=purple:s=16x16:r=25 -t 1.00 -c:v libx264 -threads 1 -pix_fmt yuv420p -flags +bitexact -fflags +bitexact -map_metadata -1 -movflags +faststart -y synthetic-valid.mp4
ffmpeg -hide_banner -loglevel error -f lavfi -i color=c=purple:s=16x16:r=100 -t 20.49 -c:v libx264 -threads 1 -pix_fmt yuv420p -flags +bitexact -fflags +bitexact -map_metadata -1 -movflags +faststart -y synthetic-over-duration.mp4
```

| File | Duration | Bytes | SHA-256 |
| --- | --- | --- | --- |
| synthetic-valid.mp4 | 1.000 seconds | 2179 | 729c392a8f77dea6990329f71c6f8e8e7e832f232910efe6b0c5e8a23a16013d |
| synthetic-over-duration.mp4 | 20.490 seconds | 54310 | ac91bd681120bd0dfb3830c45ed2d800abbdf8b147131db3b1ff5ecb7ce4b8fc |

The browser constructs an oversized fixture in memory; no large binary is stored in Git. All hosted uploads target newly created staging accounts and are removed by the owned-fixture cleanup.
