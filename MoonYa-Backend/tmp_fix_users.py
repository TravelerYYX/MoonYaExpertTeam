path = r'd:\Project\Project\MoonYa\MoonYa-Win\MoonYa-Solution\MoonYa\wwwroot\script\dashboard\users.php'
text = open(path, 'rb').read().decode('utf-8')

start1 = text.find('\n        function renderPagination(containerId, pagination, loadFn) {')
end1 = text.find('\n        function searchUsers() {')
if start1 != -1 and end1 != -1 and start1 < end1:
    text = text[:start1] + '\n' + text[end1:]
    print('Removed renderPagination')
else:
    print('renderPagination not found or boundaries invalid', start1, end1)

start2 = text.find('\n        function showModal(content) {')
end2 = text.find('\n        function editRealName(userId, currentRealName) {')
if start2 != -1 and end2 != -1 and start2 < end2:
    text = text[:start2] + '\n' + text[end2:]
    print('Removed showModal/hideModal')
else:
    print('showModal not found or boundaries invalid', start2, end2)

open(path, 'wb').write(text