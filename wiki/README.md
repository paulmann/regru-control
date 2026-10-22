# Wiki (mirror)

These pages mirror the GitHub wiki of this project. GitHub creates the native
wiki repository only after the first page is created in a signed-in browser,
so the content is kept here as well.

To publish it as the native GitHub wiki:

1. Open `https://github.com/<owner>/regru-control/wiki` while signed in and
   create any first page (for example, "Home").
2. Clone the wiki repository and copy these files:

```bash
git clone https://github.com/<owner>/regru-control.wiki.git
cp wiki/*.md regru-control.wiki/
cd regru-control.wiki && git add -A && git commit -m "Import wiki" && git push
```

`_Sidebar.md` and `_Footer.md` define the wiki navigation and footer.